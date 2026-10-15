<?php

declare(strict_types=1);

namespace CentralVet\Ai;

/**
 * AI gateway settings from environment variables (.env.example /
 * docker-compose.yml). The API key lives only in the environment: it is
 * masked in __debugInfo()/toArray() and never appears in exception
 * messages (invalid values are reported by variable name only).
 */
final class AiConfig
{
    public const PROVIDERS = ['anthropic', 'stub'];
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];
    public const FALLBACKS = ['default', 'off'];
    public const STUB_BEHAVIORS = ['ok', 'timeout'];

    private const MASK = '*****';

    private function __construct(
        private readonly string $provider,
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $effort,
        private readonly int $maxOutputTokens,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        private readonly bool $fallbacksEnabled,
        private readonly string $stubBehavior,
        private readonly string $appEnv,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $names = [
            'AI_PROVIDER', 'ANTHROPIC_API_KEY', 'ANTHROPIC_BASE_URL', 'AI_MODEL', 'AI_EFFORT',
            'AI_MAX_OUTPUT_TOKENS', 'AI_TIMEOUT_SECONDS', 'AI_CONNECT_TIMEOUT_SECONDS',
            'AI_FALLBACKS', 'AI_STUB_BEHAVIOR', 'APP_ENV',
        ];
        $env = [];

        foreach ($names as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        return self::fromArray($env);
    }

    /**
     * @param array<string, mixed> $env
     */
    public static function fromArray(array $env): self
    {
        $provider = self::choice($env, 'AI_PROVIDER', 'anthropic', self::PROVIDERS);
        $appEnv = strtolower(self::string($env, 'APP_ENV', 'development'));

        if ($provider === 'stub' && $appEnv === 'production') {
            throw new \InvalidArgumentException('AI stub provider is not allowed in production');
        }

        $apiKey = self::string($env, 'ANTHROPIC_API_KEY', '');

        return new self(
            $provider,
            $apiKey === '' ? null : $apiKey,
            rtrim(self::string($env, 'ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
            self::string($env, 'AI_MODEL', 'claude-sonnet-5-5'),
            self::choice($env, 'AI_EFFORT', 'low', self::EFFORTS),
            self::integer($env, 'AI_MAX_OUTPUT_TOKENS', 8000, 256, 32000),
            self::integer($env, 'AI_TIMEOUT_SECONDS', 30, 5, 60),
            self::integer($env, 'AI_CONNECT_TIMEOUT_SECONDS', 5, 1, 15),
            self::choice($env, 'AI_FALLBACKS', 'default', self::FALLBACKS) === 'default',
            self::choice($env, 'AI_STUB_BEHAVIOR', 'ok', self::STUB_BEHAVIORS),
            $appEnv,
        );
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function effort(): string
    {
        return $this->effort;
    }

    public function maxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->connectTimeoutSeconds;
    }

    public function fallbacksEnabled(): bool
    {
        return $this->fallbacksEnabled;
    }

    public function stubBehavior(): string
    {
        return $this->stubBehavior;
    }

    public function appEnv(): string
    {
        return $this->appEnv;
    }

    /**
     * Safe representation: the API key is always masked (null when unset).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'apiKey' => $this->apiKey === null ? null : self::MASK,
            'baseUrl' => $this->baseUrl,
            'model' => $this->model,
            'effort' => $this->effort,
            'maxOutputTokens' => $this->maxOutputTokens,
            'timeoutSeconds' => $this->timeoutSeconds,
            'connectTimeoutSeconds' => $this->connectTimeoutSeconds,
            'fallbacksEnabled' => $this->fallbacksEnabled,
            'stubBehavior' => $this->stubBehavior,
            'appEnv' => $this->appEnv,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function string(array $env, string $name, string $default): string
    {
        $value = $env[$name] ?? null;

        if ($value === null || !is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * @param array<string, mixed> $env
     * @param list<string> $allowed
     */
    private static function choice(array $env, string $name, string $default, array $allowed): string
    {
        $value = strtolower(self::string($env, $name, $default));

        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid value for %s (allowed: %s)', $name, implode(', ', $allowed)));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function integer(array $env, string $name, int $default, int $min, int $max): int
    {
        $raw = self::string($env, $name, (string) $default);

        if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
            throw new \InvalidArgumentException(sprintf('Invalid value for %s (expected an integer between %d and %d)', $name, $min, $max));
        }

        return (int) $raw;
    }
}
