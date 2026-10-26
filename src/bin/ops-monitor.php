<?php

declare(strict_types=1);

/**
 * Monitor operacional (Etapa 4, T-12), para o cron a cada 15 minutos (a
 * partir da raiz de src/): heartbeats do reconciliador e do backup, eventos
 * de cobrança e pedidos parados, falhas e filas de mensagens e documentos e
 * o probe do storage de documentos. Cada alerta vai ao BILLING_OPERATOR_EMAIL
 * por OperatorAlertNotifier (deduplicado por 6 h; vazio: só o error_log).
 * Regras em CentralVet\Operations\OperationalMonitor.
 *
 * Imprime `ops-monitor alerts=<n> notified=<n> keys=<chaves|->` e grava o
 * heartbeat `ops-monitor`. Sai 0 sem alertas, 2 com alertas e 1 em exceção
 * (`ops-monitor error=<classe>`; só a classe vai para o log). Execuções
 * sobrepostas não acumulam: a trava de arquivo (`flock`, como
 * billing-reconcile.php) faz a segunda sair 0.
 */

use CentralVet\Communication\EmailProviderFactory;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Operations\Contract\HeartbeatRepositoryInterface;
use CentralVet\Operations\OperationalMonitor;
use CentralVet\Operations\OperatorAlertNotifier;
use CentralVet\Persistence\PdoAlertStateRepository;
use CentralVet\Persistence\PdoHeartbeatRepository;
use CentralVet\Persistence\PdoOperationalCounts;
use CentralVet\Storage\DocumentStorageFactory;
use CentralVet\Storage\Exception\StorageException;
use CentralVet\Storage\StorageInterface;
use CentralVet\Storage\StoredObjectMetadata;
use CentralVet\Tenancy\TenantContext;

// Só por linha de comando: em hospedagem Apache sem bloqueio de bin/, um GET
// anônimo dispararia o monitor (e-mails ao operador).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

chdir(dirname(__DIR__));

$lockHandle = fopen(sys_get_temp_dir() . '/centralvet-ops-monitor-' . md5(__DIR__) . '.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, 'ops-monitor skipped_locked' . PHP_EOL);
    exit(0);
}

try {
    require dirname(__DIR__) . '/init.php';

    $config = require dirname(__DIR__) . '/config/environment.php';
    date_default_timezone_set($config['app']['timezone']);

    $database = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['username'],
        $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $logger = JsonLogger::fromEnvironment('ops-monitor');
    $heartbeats = new PdoHeartbeatRepository($pdo);
    // Storage que nem se monta (raiz local ausente, driver inválido) também é
    // storage indisponível: vira o alerta storage_unavailable, não exit 1.
    try {
        $storage = DocumentStorageFactory::fromEnvironment(
            TenantContext::authenticated(1, (int) (getenv('DOCUMENT_SYSTEM_USER_ID') ?: 1)),
        );
    } catch (StorageException $exception) {
        error_log(sprintf('ops-monitor.php: storage %s', $exception::class));
        $storage = new class () implements StorageInterface {
            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                throw new StorageException('Document storage unavailable');
            }

            public function get(string $key): string
            {
                throw new StorageException('Document storage unavailable');
            }

            public function exists(string $key): bool
            {
                throw new StorageException('Document storage unavailable');
            }

            public function delete(string $key): void
            {
                throw new StorageException('Document storage unavailable');
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                throw new StorageException('Document storage unavailable');
            }
        };
    }
    $notifier = new OperatorAlertNotifier(
        new PdoAlertStateRepository($pdo),
        EmailProviderFactory::fromEnvironment($logger),
        (string) (getenv('BILLING_OPERATOR_EMAIL') ?: ''),
    );

    $now = new DateTimeImmutable();
    $alerts = (new OperationalMonitor(new PdoOperationalCounts($pdo), $heartbeats, $storage))->check($now);

    $notified = 0;
    $keys = [];
    foreach ($alerts as $alert) {
        $keys[] = $alert->key;
        if ($notifier->notify($alert->key, 'Central Vet Pro: alerta operacional ' . $alert->key, $alert->summary, $now)) {
            $notified++;
        }
    }

    $line = sprintf('ops-monitor alerts=%d notified=%d keys=%s', count($alerts), $notified, $keys === [] ? '-' : implode(',', $keys));
    $heartbeats->record(HeartbeatRepositoryInterface::JOB_OPS_MONITOR, $now, substr($line, strlen('ops-monitor ')));
} catch (Throwable $exception) {
    error_log(sprintf('ops-monitor.php: %s', $exception::class));
    fwrite(STDOUT, 'ops-monitor error=' . $exception::class . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, $line . PHP_EOL);
exit($alerts === [] ? 0 : 2);
