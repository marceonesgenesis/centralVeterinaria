<?php

declare(strict_types=1);

/**
 * Retenção LGPD de `billing_event.payload` (Etapa 4, T-13), para o cron
 * diário da hospedagem (a partir da raiz de src/): esvazia o payload dos
 * eventos `processed`/`ignored` recebidos há mais de 30 dias, sem apagar a
 * linha. Regras em CentralVet\Billing\BillingEventPayloadRetention.
 *
 * Imprime `{"purged":<n>,"batches":<n>}`, grava o heartbeat
 * `billing-event-retention` e sai 0. Execuções sobrepostas não acumulam
 * (`flock`, como `bin/ai-conversation-retention.php`): com a trava ocupada,
 * imprime `{"skipped":"locked"}` e sai 0. Sem o arquivo de trava, registra
 * "lock file unavailable" e sai 1. Em exceção, só a classe vai para o
 * error_log (nunca a mensagem) e o código é 1.
 */

use CentralVet\Billing\BillingEventPayloadRetention;
use CentralVet\Operations\Contract\HeartbeatRepositoryInterface;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Persistence\PdoHeartbeatRepository;

// Só por linha de comando: em hospedagem Apache sem bloqueio de bin/, um GET
// anônimo dispararia a retenção.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$lockHandle = @fopen(sys_get_temp_dir() . '/centralvet-billing-event-retention-' . md5(__DIR__) . '.lock', 'c');
if ($lockHandle === false) {
    error_log('billing-event-retention: lock file unavailable');
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, json_encode(['skipped' => 'locked']) . PHP_EOL);
    exit(0);
}

try {
    // Mesmo fuso do reconciliador: o heartbeat é gravado e lido no fuso do PHP.
    $config = require dirname(__DIR__) . '/config/environment.php';
    date_default_timezone_set($config['app']['timezone']);

    $pdo = PdoConnectionFactory::fromEnvironment();
    $result = BillingEventPayloadRetention::forConnection($pdo)->runOnce();
    (new PdoHeartbeatRepository($pdo))->record(
        HeartbeatRepositoryInterface::JOB_BILLING_EVENT_RETENTION,
        new DateTimeImmutable(),
        sprintf('purged=%d batches=%d', $result['purged'], $result['batches']),
    );
} catch (\Throwable $exception) {
    error_log('billing-event-retention failed: ' . $exception::class);
    exit(1);
}

fwrite(STDOUT, json_encode($result) . PHP_EOL);
exit(0);
