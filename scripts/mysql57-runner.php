<?php
declare(strict_types=1);

// Deployment template: replace these markers locally, upload under a random
// filename, and remove it and its private bundle after verification.
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || time() > (int) '__DEPLOY_EXPIRES__'
    || !hash_equals('__DEPLOY_TOKEN__', $_SERVER['HTTP_X_CV_DEPLOY'] ?? '')) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
require __DIR__ . '/app/config/hosting-environment.php';
$directory = __DIR__ . '/app/database/__DEPLOY_DIRECTORY__';
$statementNumber = null;
$lock = null;

try {
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';port=3306;dbname=' . getenv('DB_DATABASE') . ';charset=utf8mb4',
        getenv('DB_USERNAME'), getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'marceo22_centralvet') {
        throw new RuntimeException('Unexpected target database');
    }
    // Keep numeric/date coercion from silently bypassing validation on hosting
    // servers whose global SQL mode is permissive. Only this connection changes.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
    $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $action = $_POST['action'] ?? '';
    if ($action === 'inspect') {
        echo json_encode([
            'server' => $pdo->query('SELECT VERSION() AS version, DATABASE() AS db, @@sql_mode AS sql_mode, @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust_creators')->fetch(PDO::FETCH_ASSOC),
            'tables' => $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
            'schema_privileges' => $pdo->query("SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN),
            'global_privileges' => $pdo->query('SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES')->fetchAll(PDO::FETCH_COLUMN),
        ], JSON_THROW_ON_ERROR);
    } elseif ($action === 'apply') {
        $lock = fopen($directory . '/deployment.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another deployment request is running');
        }
        $progressPath = $directory . '/progress.json';
        $progress = is_file($progressPath) ? json_decode(file_get_contents($progressPath), true, 512, JSON_THROW_ON_ERROR) : ['next' => 0, 'failed' => false];
        $index = filter_var($_POST['stage'] ?? null, FILTER_VALIDATE_INT);
        if ($progress['failed'] || $index === false || $index !== $progress['next'] || !isset($manifest['stages'][$index])) {
            throw new RuntimeException('Stage out of order, already applied, or deployment failed; inspect before proceeding');
        }
        if ($index === 0 && $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) !== []) {
            throw new RuntimeException('Initial installation requires an empty database');
        }
        $stage = json_decode(file_get_contents($directory . '/' . $manifest['stages'][$index]['file']), true, 512, JSON_THROW_ON_ERROR);
        // Persist a failure marker BEFORE executing DDL: an interrupted HTTP request
        // must never allow accidental reapplication of a partially executed stage.
        file_put_contents($progressPath, json_encode(['next' => $index, 'failed' => true]));
        foreach ($stage['statements'] as $statementNumber => $sql) {
            $pdo->exec($sql);
        }
        file_put_contents($progressPath, json_encode(['next' => $index + 1, 'failed' => false]));
        echo json_encode(['applied' => $stage['file'], 'statements' => count($stage['statements']), 'checks' => count($stage['checks'])], JSON_THROW_ON_ERROR);
    } elseif ($action === 'verify') {
        $results = [];
        foreach ($manifest['verification'] as $file => $queries) {
            foreach ($queries as $query) {
                $results[$file][] = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $violations = [];
        foreach ($manifest['checks'] as $check) {
            $violations[$check['name']] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $check['table'] . '` WHERE (' . $check['expression'] . ') IS FALSE')->fetchColumn();
        }
        echo json_encode([
            'verification' => $results,
            'check_violations' => $violations,
            'migrations' => $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC),
            'triggers' => $pdo->query('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'tables' => $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
        ], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Unknown action');
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage(), 'statement_index' => $statementNumber]);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
