<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One message of a copilot conversation (`ai_conversation_message`, 0015).
 *
 * `user` carries the (already minimized) question; `assistant` carries the
 * answer plus the "Consultei: …" tool summary; a failed turn is an
 * `assistant` message whose content is the `ai_*` error code. The tool
 * summary is stored as JSON of at most TOOL_SUMMARY_MAX_BYTES (entries
 * beyond that are dropped). `tenant_id` is filled by the repository from
 * the context. Content never goes into exception messages.
 */
final class AiConversationMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLES = [self::ROLE_USER, self::ROLE_ASSISTANT];
    public const TOOL_SUMMARY_MAX_BYTES = 2000;

    /**
     * @param list<array{tool: string, label: string, count: int}> $toolSummary
     */
    private function __construct(
        private ?int $id,
        private readonly int $conversationId,
        private readonly string $role,
        private readonly string $content,
        private readonly array $toolSummary,
        private readonly bool $failed,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ($conversationId <= 0) {
            throw new InvalidArgumentException('conversation_id must be positive');
        }

        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown message role');
        }

        if ($failed && $role !== self::ROLE_ASSISTANT) {
            throw new InvalidArgumentException('Only an assistant message may be failed');
        }

        if ($failed && preg_match('/^ai_[a-z0-9_]+$/', $content) !== 1) {
            throw new InvalidArgumentException('A failed message carries an ai_* code');
        }

        if (trim($content) === '') {
            throw new InvalidArgumentException('Message content must not be empty');
        }
    }

    public static function user(int $conversationId, string $content, DateTimeImmutable $now): self
    {
        return new self(null, $conversationId, self::ROLE_USER, $content, [], false, $now);
    }

    /** @param list<array{tool: string, label: string, count: int}> $toolSummary */
    public static function assistant(int $conversationId, string $content, array $toolSummary, DateTimeImmutable $now): self
    {
        return new self(null, $conversationId, self::ROLE_ASSISTANT, $content, self::fitSummary($toolSummary), false, $now);
    }

    public static function failed(int $conversationId, string $reason, DateTimeImmutable $now): self
    {
        return new self(null, $conversationId, self::ROLE_ASSISTANT, $reason, [], true, $now);
    }

    /** @param array<string, mixed> $row raw column values of `ai_conversation_message`. */
    public static function reconstitute(array $row): self
    {
        $summary = [];

        if (isset($row['tool_summary']) && $row['tool_summary'] !== '') {
            $decoded = json_decode((string) $row['tool_summary'], true);
            $summary = is_array($decoded) ? self::fitSummary($decoded) : [];
        }

        $message = new self(
            id: null,
            conversationId: (int) $row['conversation_id'],
            role: (string) $row['role'],
            content: (string) $row['content'],
            toolSummary: $summary,
            failed: (bool) (int) ($row['failed'] ?? 0),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );

        if (isset($row['id'])) {
            $message->assignId((int) $row['id']);
        }

        return $message;
    }

    /** Used by the repository after the insert. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Message already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Column values for `ai_conversation_message` (without `tenant_id`,
     * filled by the repository). `tool_summary` is JSON or null.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'role' => $this->role,
            'content' => $this->content,
            'tool_summary' => $this->toolSummary === [] ? null : self::encode($this->toolSummary),
            'failed' => $this->failed ? 1 : 0,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s.u'),
        ];
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function content(): string
    {
        return $this->content;
    }

    /** @return list<array{tool: string, label: string, count: int}> */
    public function toolSummary(): array
    {
        return $this->toolSummary;
    }

    /**
     * Named isFailed() because PHP forbids a static and an instance method
     * with the same name, and the static factory failed() is the contract.
     */
    public function isFailed(): bool
    {
        return $this->failed;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Normalizes the entries and drops the tail until the JSON fits.
     *
     * @param array<mixed> $summary
     * @return list<array{tool: string, label: string, count: int}>
     */
    private static function fitSummary(array $summary): array
    {
        $entries = [];

        foreach ($summary as $entry) {
            if (!is_array($entry) || !isset($entry['tool'], $entry['label'])) {
                throw new InvalidArgumentException('Tool summary entry needs tool, label and count');
            }

            $entries[] = [
                'tool' => (string) $entry['tool'],
                'label' => (string) $entry['label'],
                'count' => max(0, (int) ($entry['count'] ?? 0)),
            ];
        }

        while ($entries !== [] && strlen(self::encode($entries)) > self::TOOL_SUMMARY_MAX_BYTES) {
            array_pop($entries);
        }

        return $entries;
    }

    /** @param list<array{tool: string, label: string, count: int}> $entries */
    private static function encode(array $entries): string
    {
        return json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
