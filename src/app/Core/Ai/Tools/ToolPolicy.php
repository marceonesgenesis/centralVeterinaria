<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

use CentralVet\Ai\PiiMinimizer;
use CentralVet\Audit\AuditRedactor;
use CentralVet\Domain\Contract\McpToolCallRepositoryInterface;
use CentralVet\Domain\McpToolCall;
use Closure;

/**
 * Fail-closed gate in front of every copilot tool (Fase 8B spec § 3.1).
 * Checks, in order: ToolContext matching the registry's TenantContext
 * (`forbidden`), offered + registered (`not_offered`), input schema
 * (`invalid_input`), RBAC of the tool's screen permission (`forbidden`, read
 * from the ToolRegistry cache: the policy never asks the RBAC policy again,
 * so each tool gets one decision per request), entity scope (`not_found`) and the per-user
 * rate limit (`rate_limited`). Then runs the tool: ToolInputException →
 * `invalid_input`, any other throwable → `error`, slower than TIMEOUT_MS →
 * `timeout` (result discarded). Output is cut to ToolResult::MAX_ROWS and
 * MAX_BYTES, free-text fields go through PiiMinimizer over the full text and
 * only then are cut to ToolResult::$excerptLengths, and every call writes
 * one `mcp_tool_call` row with the input redacted and minimized.
 */
final class ToolPolicy
{
    public const TIMEOUT_MS = 5000;
    public const MAX_BYTES = 16384;
    /** Upper bound of `mcp_tool_call.input_minimized` (column is TEXT). */
    public const MAX_INPUT_BYTES = 4096;

    private const MESSAGES = [
        'not_offered' => 'sem permissão',
        'forbidden' => 'sem permissão',
        'not_found' => 'não encontrado',
        'rate_limited' => 'limite atingido',
        'invalid_input' => 'entrada inválida',
        'error' => 'erro ao consultar',
        'timeout' => 'erro ao consultar',
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly Closure $monotonicMs;

    /**
     * $minimizer is accepted for the planned signature only: PiiMinimizer is
     * a static utility with a private constructor, so callers pass null.
     */
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly JsonSchemaValidator $validator,
        private readonly McpToolCallRepositoryInterface $calls,
        private readonly ToolRateLimiter $rateLimiter,
        ?PiiMinimizer $minimizer = null,
        ?Closure $monotonicMs = null,
    ) {
        $this->monotonicMs = $monotonicMs ?? static fn (): int => intdiv(hrtime(true), 1_000_000);
    }

    /**
     * Never throws: any failure at any stage becomes a denied outcome with a
     * fixed message, one `denied` audit row (best effort) and a log line
     * with the exception class and correlation_id only.
     *
     * @param array<string, mixed> $input as sent by the model (untrusted)
     * @param list<string> $offeredNames tools offered to the model in this turn
     */
    public function execute(ToolContext $context, string $family, string $toolName, array $input, array $offeredNames): ToolOutcome
    {
        $started = $this->now();
        $inputMinimized = '{}';

        try {
            $inputMinimized = $this->minimizeInput($input);

            // Defense in depth: RBAC was decided for the registry's
            // TenantContext, so a ToolContext of another tenant, unit or
            // user is denied before the tool is even looked up.
            if (!$this->registry->sameContext($context)) {
                return $this->deny($context, $toolName, $inputMinimized, 'forbidden', $started);
            }

            $offered = in_array($toolName, $offeredNames, true);
            $denial = $offered ? $this->registry->denialReason($family, $toolName) : ToolRegistry::NOT_OFFERED;
            $tool = $denial === ToolRegistry::NOT_OFFERED ? null : $this->registry->registered($family, $toolName);
            if ($tool === null) {
                return $this->deny($context, $toolName, $inputMinimized, 'not_offered', $started);
            }

            if ($this->validator->validate($tool->inputSchema(), $input) !== []) {
                return $this->deny($context, $toolName, $inputMinimized, 'invalid_input', $started);
            }

            if ($denial !== null) {
                return $this->deny($context, $toolName, $inputMinimized, 'forbidden', $started);
            }

            $tool->assertInScope($context, $input);

            if (!$this->withinRateLimit($context, $toolName)) {
                return $this->deny($context, $toolName, $inputMinimized, 'rate_limited', $started);
            }

            $result = $tool->execute($context, $input);

            if ($this->now() - $started > self::TIMEOUT_MS) {
                return $this->deny($context, $toolName, $inputMinimized, 'timeout', $started);
            }

            [$content, $rowCount, $truncated] = $this->content($result);
            $this->audit($context, $toolName, $inputMinimized, McpToolCall::DECISION_ALLOWED, null, $rowCount, $truncated, $started);

            return new ToolOutcome($toolName, false, $content, McpToolCall::DECISION_ALLOWED, null, $rowCount, $truncated);
        } catch (\Throwable $failure) {
            $reason = match (true) {
                $failure instanceof ToolInputException => 'invalid_input',
                $failure instanceof ToolEntityNotFoundException => 'not_found',
                default => 'error',
            };
            if ($reason === 'error') {
                $this->log($toolName, $failure, $context->correlationId, 'failed');
            }

            return $this->deny($context, $toolName, $inputMinimized, $reason, $started);
        }
    }

    /** Fail-closed: a broken limiter backend denies the call. */
    private function withinRateLimit(ToolContext $context, string $toolName): bool
    {
        try {
            return $this->rateLimiter->attempt($context->tenantId, $context->userId);
        } catch (\Throwable $failure) {
            $this->log($toolName, $failure, $context->correlationId, 'rate limiter failed');

            return false;
        }
    }

    private function now(): int
    {
        try {
            return (int) ($this->monotonicMs)();
        } catch (\Throwable) {
            return intdiv(hrtime(true), 1_000_000);
        }
    }

    private function log(string $toolName, \Throwable $failure, string $correlationId, string $what): void
    {
        error_log(sprintf(
            '[ai-tool] %s %s with %s (correlation_id=%s)',
            self::safeName($toolName),
            $what,
            $failure::class,
            $correlationId,
        ));
    }

    /** @return array{0: string, 1: int, 2: bool} */
    private function content(ToolResult $result): array
    {
        $rows = array_slice($result->data, 0, ToolResult::MAX_ROWS);
        $truncated = $result->truncated || $result->rowCount > ToolResult::MAX_ROWS;

        foreach ($rows as $index => $row) {
            foreach ($result->freeTextFields as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $rows[$index][$field] = PiiMinimizer::minimize($row[$field], $result->knownNames);
                }
            }
            // Cut only after minimizing: a cut inside a phone, CPF or name
            // would leave a fragment the minimizer no longer recognizes.
            foreach ($result->excerptLengths as $field => $max) {
                if (isset($rows[$index][$field]) && is_string($rows[$index][$field])) {
                    $rows[$index][$field] = ToolFormat::excerpt($rows[$index][$field], $max);
                }
            }
        }

        $content = $this->encodeRows($rows, $truncated);
        while (strlen($content) > self::MAX_BYTES && $rows !== []) {
            array_pop($rows);
            $truncated = true;
            $content = $this->encodeRows($rows, $truncated);
        }

        return [$content, count($rows), $truncated];
    }

    /** @param list<array<string, scalar|null>> $rows */
    private function encodeRows(array $rows, bool $truncated): string
    {
        $json = json_encode(['rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated], self::JSON_FLAGS);

        return is_string($json) ? $json : (string) json_encode(['rows' => [], 'row_count' => 0, 'truncated' => true]);
    }

    private function deny(ToolContext $context, string $toolName, string $inputMinimized, string $reason, int $started): ToolOutcome
    {
        try {
            $this->audit($context, $toolName, $inputMinimized, McpToolCall::DECISION_DENIED, $reason, 0, false, $started);
        } catch (\Throwable $failure) {
            $this->log($toolName, $failure, $context->correlationId, 'audit failed');
        }

        return new ToolOutcome(
            $toolName,
            true,
            (string) json_encode(['error' => self::MESSAGES[$reason]], self::JSON_FLAGS),
            McpToolCall::DECISION_DENIED,
            $reason,
            0,
            false,
        );
    }

    private function audit(
        ToolContext $context,
        string $toolName,
        string $inputMinimized,
        string $decision,
        ?string $reason,
        int $rowCount,
        bool $truncated,
        int $started,
    ): void {

        $this->calls->insert(McpToolCall::record(
            $context->tenantId,
            $context->unitId,
            $context->userId,
            $context->conversationId,
            $context->correlationId,
            self::safeName($toolName),
            $inputMinimized,
            $decision,
            $reason,
            $rowCount,
            $truncated,
            max(0, $this->now() - $started),
        ));
    }

    /** @param array<string, mixed> $input */
    private function minimizeInput(array $input): string
    {
        $json = json_encode(self::minimizeStrings(AuditRedactor::redact($input)), self::JSON_FLAGS);
        if (!is_string($json)) {
            return '{}';
        }
        if (strlen($json) > self::MAX_INPUT_BYTES) {
            $json = (string) json_encode(['truncated' => mb_strcut($json, 0, intdiv(self::MAX_INPUT_BYTES, 2) - 32, 'UTF-8')], self::JSON_FLAGS);
        }

        return strlen($json) > self::MAX_INPUT_BYTES ? '{"truncated":true}' : $json;
    }

    /** Tool name as stored: 1–60 bytes of valid UTF-8 (McpToolCall rejects others). */
    private static function safeName(string $toolName): string
    {
        $name = mb_strcut(mb_scrub($toolName, 'UTF-8'), 0, 60, 'UTF-8');

        return trim($name) === '' ? 'unknown' : $name;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function minimizeStrings(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::minimizeStrings($value);
            } elseif (is_string($value)) {
                $values[$key] = PiiMinimizer::minimize($value, []);
            }
        }

        return $values;
    }
}
