<?php

declare(strict_types=1);

/**
 * Runs the document sweeper once (Fase 7B): re-publishes the
 * `document.generate` job of every document left `queued` for more than 10
 * minutes, in every active tenant. For the gate and the hosting cron; the
 * worker runs the same DocumentSweeper::runOnce() on its own tick.
 *
 * Prints the JSON of counters (`tenants`, `republished`, `errors`) on
 * stdout. Exit 0 on success, 1 when any tenant failed (`errors` > 0) or on
 * a connection failure (only the exception class is printed, never its
 * message).
 */

require __DIR__ . '/../vendor/autoload.php';

use CentralVet\Application\DocumentJobPublisher;
use CentralVet\Document\DocumentSweeper;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Queue\RedisQueue;

$envInt = static function (string $name, int $default): int {
    $value = getenv($name);

    return ($value === false || trim($value) === '' || !is_numeric(trim($value))) ? $default : (int) trim($value);
};

try {
    $logger = JsonLogger::fromEnvironment('document-sweep');
    $result = DocumentSweeper::forConnection(
        PdoConnectionFactory::fromEnvironment(),
        new DocumentJobPublisher(RedisQueue::fromEnvironment()),
        $logger,
        $envInt('DOCUMENT_SYSTEM_USER_ID', 1),
    )->runOnce();
} catch (\Throwable $exception) {
    fwrite(STDOUT, json_encode(['error' => $exception::class]) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, json_encode($result) . PHP_EOL);
exit($result['errors'] > 0 ? 1 : 0);
