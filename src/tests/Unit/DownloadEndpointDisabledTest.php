<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * S6: o endpoint genérico download.php fica desabilitado (404, corpo vazio),
 * sem ler o arquivo pedido nem registrar o caminho no log.
 */
final class DownloadEndpointDisabledTest
{
    private const MARKER = 'S6-DOWNLOAD-MARKER-7f3a9c';

    public function testRequestForExistingFileAnswers404WithEmptyBody(): void
    {
        $result = $this->runDownload(__FILE__);

        Assert::same(404, $result['code'], 'download.php must answer 404: ' . $result['raw']);
        Assert::same(0, $result['body_length'], 'download.php must answer an empty body');
        Assert::false(str_contains($result['raw'], self::MARKER), 'no byte of the requested file may leak');
    }

    public function testLogsDisabledEventWithoutRequestedPath(): void
    {
        $result = $this->runDownload(__FILE__);

        Assert::stringContains('download.disabled', $result['raw'], 'download.php must log download.disabled');
        Assert::false(str_contains($result['raw'], basename(__FILE__)), 'the log must not carry the requested path');
    }

    public function testSourceHasNoFileReadingCalls(): void
    {
        $source = (string) file_get_contents($this->endpoint());

        foreach (['readfile', 'fopen', 'file_get_contents', 'realpath'] as $call) {
            Assert::false(str_contains($source, $call), "download.php must not call {$call}");
        }
    }

    /**
     * @return array{code: mixed, body_length: int, raw: string}
     */
    private function runDownload(string $requestedFile): array
    {
        $script = <<<'PHP'
            [$endpoint, $file] = [$argv[1], $argv[2]];
            $_GET['file'] = $file;
            ob_start();
            register_shutdown_function(static function (): void {
                $body = '';
                while (ob_get_level() > 0) {
                    $body = ob_get_clean() . $body;
                }
                echo "\n", 'RESULT=', json_encode(['code' => http_response_code(), 'body_length' => strlen($body), 'body' => $body]), "\n";
            });
            include $endpoint;
            PHP;

        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=1 -d error_log= -r '
            . escapeshellarg($script) . ' ' . escapeshellarg($this->endpoint()) . ' ' . escapeshellarg($requestedFile) . ' 2>&1';
        $raw = (string) shell_exec($command);

        Assert::true((bool) preg_match('/^RESULT=(.*)$/m', $raw, $match), 'subprocess must print RESULT: ' . $raw);
        $decoded = json_decode($match[1], true);
        Assert::true(is_array($decoded), 'RESULT must be JSON: ' . $raw);

        return ['code' => $decoded['code'], 'body_length' => (int) $decoded['body_length'], 'raw' => $raw];
    }

    private function endpoint(): string
    {
        return dirname(__DIR__, 2) . '/download.php';
    }
}
