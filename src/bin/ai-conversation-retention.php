<?php

declare(strict_types=1);

/**
 * Runs the 90-day retention of the copilot conversations once (Fase 8B,
 * T-12), for the hosting cron: deletes, in every tenant, the conversations
 * idle for more than 90 days (messages by cascade), in batches; the audit
 * (`mcp_tool_call`, `ai_interaction`) stays.
 *
 * Prints one JSON line `{"deleted":n,"batches":b}` on stdout and exits 0.
 * On any exception, only the exception class goes to error_log (never its
 * message) and the exit code is 1.
 */

require __DIR__ . '/../vendor/autoload.php';

use CentralVet\Application\AiConversationRetention;
use CentralVet\Persistence\PdoConnectionFactory;

try {
    $result = AiConversationRetention::forConnection(PdoConnectionFactory::fromEnvironment())->runOnce();
} catch (\Throwable $exception) {
    error_log('ai-conversation-retention failed: ' . $exception::class);
    exit(1);
}

fwrite(STDOUT, json_encode($result) . PHP_EOL);
exit(0);
