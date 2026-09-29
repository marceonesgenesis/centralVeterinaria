<?php

declare(strict_types=1);

namespace CentralVet\Observability\Logging;

use CentralVet\Observability\CorrelationId\CorrelationIdContext;

/**
 * Structured JSON logger, one line per event. Writing to php://stderr by
 * default means container runtimes (this project's `json-file` Docker
 * logging driver, see docker-compose.yml) capture every line without any
 * extra shipping agent or SaaS provider.
 *
 * Deliberately separate from `audit_log` (business auditing, ADR 0003) and
 * from the legacy request/access/sql log services under app/service/log
 * (Adianti's own DB-backed audit trail). This logger is for technical
 * events: application lifecycle, queue processing, errors.
 */
final class JsonLogger implements LoggerInterface
{
    private const SENSITIVE_KEYS = [
        'password', 'repassword', 'confirm_password', 'password1', 'password2',
        'token', 'auth_token', 'authorization', 'secret', 'api_key',
    ];

    private const LEVEL_ORDER = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    /** @var resource */
    private $stream;

    private int $minLevel;

    public function __construct(
        private readonly string $service,
        private readonly string $environment,
        string $streamTarget = 'php://stderr',
        string $minLevel = 'debug',
    ) {
        $stream = fopen($streamTarget, 'a');

        if ($stream === false) {
            throw new \RuntimeException("Unable to open log stream: {$streamTarget}");
        }

        $this->stream = $stream;
        $this->minLevel = self::LEVEL_ORDER[$minLevel] ?? 0;
    }

    public static function fromEnvironment(string $service): self
    {
        return new self(
            $service,
            (string) (getenv('APP_ENV') ?: 'development'),
            (string) (getenv('LOG_STREAM') ?: 'php://stderr'),
            (string) (getenv('LOG_MIN_LEVEL') ?: 'debug'),
        );
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if ((self::LEVEL_ORDER[$level] ?? 0) < $this->minLevel) {
            return;
        }

        $entry = [
            'timestamp' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP'),
            'level' => $level,
            'service' => $this->service,
            'environment' => $this->environment,
            'message' => $message,
            'correlation_id' => CorrelationIdContext::current(),
            'context' => self::redact($context),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        fwrite($this->stream, $line . PHP_EOL);
    }

    private static function redact(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::redact($value);
                continue;
            }

            $result[$key] = in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true) ? '*****' : $value;
        }

        return $result;
    }
}
