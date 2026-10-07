<?php

declare(strict_types=1);

/**
 * Runs the communication scheduler once (Fase 7A): generates the automatic
 * reminders of every active tenant and publishes the e-mails created plus
 * the ones stuck in `queued`. For the gate and the hosting cron; the worker
 * runs the same CommunicationScheduler::runOnce() on its own tick.
 *
 * Prints the JSON of counters on stdout. Exit 0 on success, 1 when any
 * tenant failed (`errors` > 0) or on a connection failure (only the
 * exception class is printed, never its message).
 */

require __DIR__ . '/../vendor/autoload.php';

use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Communication\CommunicationScheduler;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Queue\RedisQueue;

$envInt = static function (string $name, int $default): int {
    $value = getenv($name);

    return ($value === false || trim($value) === '' || !is_numeric(trim($value))) ? $default : (int) trim($value);
};

try {
    $logger = JsonLogger::fromEnvironment('communication-scheduler');
    $result = CommunicationScheduler::forConnection(
        PdoConnectionFactory::fromEnvironment(),
        new MessageQueuePublisher(RedisQueue::fromEnvironment()),
        $logger,
        max(0, $envInt('COMMUNICATION_RECEIVABLE_REMINDER_DAYS', 7)),
        $envInt('COMMUNICATION_SYSTEM_USER_ID', 1),
    )->runOnce();
} catch (\Throwable $exception) {
    fwrite(STDOUT, json_encode(['error' => $exception::class]) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, json_encode($result) . PHP_EOL);
exit($result['errors'] > 0 ? 1 : 0);
