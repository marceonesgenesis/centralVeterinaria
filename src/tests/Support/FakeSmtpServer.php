<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

/**
 * In-process fake SMTP server for delivery tests (Etapa 4, T-04).
 *
 * start() runs a child PHP process (proc_open, no shell) that listens on
 * 127.0.0.1 on a free port, serves one plain SMTP session (no TLS, no AUTH)
 * and answers 250/354/221. stop() waits for the child and returns the
 * transcript: every line the client sent, DATA included. No network beyond
 * loopback; the child gives up after ACCEPT_TIMEOUT seconds without a client.
 */
final class FakeSmtpServer
{
    private const ACCEPT_TIMEOUT = 10;

    private const CHILD = <<<'PHP'
        $timeout = (int) ($argv[1] ?? 10);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            fwrite(STDOUT, "0\n");
            exit(1);
        }
        $name = (string) stream_socket_get_name($server, false);
        fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1) . "\n");
        fflush(STDOUT);
        $conn = @stream_socket_accept($server, $timeout);
        if ($conn === false) {
            exit(0);
        }
        stream_set_timeout($conn, $timeout);
        $transcript = '';
        $inData = false;
        fwrite($conn, "220 fake.smtp.test ESMTP\r\n");
        while (($line = fgets($conn)) !== false) {
            $transcript .= $line;
            if ($inData) {
                if ($line === ".\r\n" || $line === ".\n") {
                    $inData = false;
                    fwrite($conn, "250 2.0.0 queued\r\n");
                }
                continue;
            }
            $verb = strtoupper(substr(trim($line), 0, 4));
            if ($verb === 'EHLO') {
                fwrite($conn, "250-fake.smtp.test\r\n250 8BITMIME\r\n");
            } elseif ($verb === 'DATA') {
                $inData = true;
                fwrite($conn, "354 end with <CRLF>.<CRLF>\r\n");
            } elseif ($verb === 'QUIT') {
                fwrite($conn, "221 bye\r\n");
                break;
            } else {
                fwrite($conn, "250 OK\r\n");
            }
        }
        fclose($conn);
        fwrite(STDOUT, $transcript);
        PHP;

    /** @param array<int, resource> $pipes */
    private function __construct(
        private readonly mixed $process,
        private readonly array $pipes,
        private readonly int $port,
    ) {
    }

    public static function start(): self
    {
        $process = proc_open(
            [PHP_BINARY, '-n', '-r', self::CHILD, (string) self::ACCEPT_TIMEOUT],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('FakeSmtpServer: could not start the child process');
        }

        $port = (int) trim((string) fgets($pipes[1]));
        if ($port <= 0) {
            $error = stream_get_contents($pipes[2]);
            proc_terminate($process);
            proc_close($process);
            throw new \RuntimeException('FakeSmtpServer: could not listen on 127.0.0.1: ' . $error);
        }

        return new self($process, $pipes, $port);
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Waits for the session to end and returns the client's lines.
     */
    public function stop(): string
    {
        fclose($this->pipes[0]);
        $transcript = (string) stream_get_contents($this->pipes[1]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);

        return $transcript;
    }
}
