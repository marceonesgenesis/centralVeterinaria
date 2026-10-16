<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * One provider-independent completion request. `system` and `userContent`
 * carry clinical text already minimized; they are never logged.
 *
 * Tool use (Fase 8B): `messages` is the conversation in Messages API shape,
 * list<array{role: 'user'|'assistant', content: string|list<array>}> with
 * `text`, `tool_use` (`id`/`name`/`input`) and `tool_result`
 * (`tool_use_id`/`content`/`is_error`) blocks; when it is not empty the
 * gateway ignores `userContent`. `tools` is
 * list<array{name: string, description: string, input_schema: array}>.
 */
final class AiCompletionRequest
{
    public function __construct(
        public readonly string $purpose,
        public readonly string $promptVersion,
        public readonly string $system,
        public readonly string $userContent,
        public readonly array $messages = [],
        public readonly array $tools = [],
    ) {
        AiPurpose::assertValid($purpose);

        if (trim($promptVersion) === '') {
            throw new InvalidArgumentException('Prompt version is required');
        }

        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || !(is_string($message['content'] ?? null) || is_array($message['content'] ?? null))) {
                throw new InvalidArgumentException('Invalid completion message');
            }
        }

        foreach ($tools as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null) || $tool['name'] === ''
                || !is_string($tool['description'] ?? null) || !is_array($tool['input_schema'] ?? null)) {
                throw new InvalidArgumentException('Invalid tool definition');
            }
        }
    }
}
