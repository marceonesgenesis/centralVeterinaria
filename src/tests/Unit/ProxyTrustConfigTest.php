<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Etapa 4, T-02: contrato de ambiente e proxies confiáveis.
 *
 * - os set_real_ip_from saem do default.conf para um arquivo incluído,
 *   escolhido por NGINX_TRUSTED_PROXIES_FILE (dev: faixas RFC1918; exemplo de
 *   produção: só um IP documental);
 * - o nginx repassa HTTPS=on ao PHP quando o proxy da frente manda
 *   X-Forwarded-Proto: https;
 * - toda porta publicada no compose escuta só em 127.0.0.1;
 * - o .env.example declara TRUST_FORWARDED_PROTO e o bloco de backup.
 */
final class ProxyTrustConfigTest
{
    private const DEV_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        Assert::true(is_file($path), $relative . ' must exist');
        $content = file_get_contents($path);
        Assert::true(is_string($content), $relative . ' must be readable');

        return $content;
    }

    /** Linhas sem comentários (# até o fim da linha). */
    private static function withoutComments(string $content): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $content) as $line) {
            $lines[] = preg_replace('/#.*$/', '', $line);
        }

        return implode("\n", $lines);
    }

    /**
     * Blocos `location ... { ... }` do default.conf (texto completo, com os
     * blocos aninhados).
     *
     * @return list<string>
     */
    private static function locationBlocks(string $conf): array
    {
        $blocks = [];
        $offset = 0;
        while (preg_match('/\blocation\b[^{;]*\{/', $conf, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $pos = $start + strlen($match[0][0]);
            $depth = 1;
            $length = strlen($conf);
            while ($depth > 0 && $pos < $length) {
                if ($conf[$pos] === '{') {
                    $depth++;
                } elseif ($conf[$pos] === '}') {
                    $depth--;
                }
                $pos++;
            }
            $blocks[] = substr($conf, $start, $pos - $start);
            $offset = $start + strlen($match[0][0]);
        }

        return $blocks;
    }

    public function testDefaultConfIncludesTrustedProxiesFileInsteadOfInlineRanges(): void
    {
        $conf = self::withoutComments(self::read('docker/nginx/default.conf'));

        Assert::false(str_contains($conf, 'set_real_ip_from'), 'default.conf must not declare set_real_ip_from');
        Assert::true(
            str_contains($conf, 'include /etc/nginx/trusted-proxies.conf;'),
            'default.conf must include /etc/nginx/trusted-proxies.conf'
        );
        Assert::true(str_contains($conf, 'real_ip_header X-Forwarded-For;'), 'real_ip_header stays in default.conf');
        Assert::true(str_contains($conf, 'real_ip_recursive on;'), 'real_ip_recursive stays in default.conf');
    }

    public function testDevTrustedProxiesKeepsCurrentRanges(): void
    {
        $conf = self::withoutComments(self::read('docker/nginx/trusted-proxies.dev.conf'));

        foreach (self::DEV_RANGES as $range) {
            Assert::true(
                str_contains($conf, 'set_real_ip_from ' . $range . ';'),
                'trusted-proxies.dev.conf must trust ' . $range
            );
        }
    }

    public function testProductionExampleTrustsOnlyDocumentationAddress(): void
    {
        $conf = self::withoutComments(self::read('docker/nginx/trusted-proxies.production.conf.example'));

        foreach ([...self::DEV_RANGES, '0.0.0.0/0'] as $range) {
            Assert::false(
                str_contains($conf, $range),
                'trusted-proxies.production.conf.example must not trust ' . $range
            );
        }
        Assert::true(
            str_contains($conf, 'set_real_ip_from 203.0.113.10;'),
            'production example must use the RFC 5737 documentation address'
        );
        Assert::same(1, preg_match_all('/\bset_real_ip_from\b/', $conf), 'production example trusts a single address');
    }

    public function testForwardedProtoHttpsIsPassedToEveryFastcgiLocation(): void
    {
        $conf = self::withoutComments(self::read('docker/nginx/default.conf'));

        Assert::same(
            1,
            preg_match('/map\s+\$http_x_forwarded_proto\s+\$cv_forwarded_https\s*\{[^}]*\bhttps\s+on\s*;[^}]*\bdefault\s+""\s*;[^}]*\}/', $conf),
            'default.conf must map X-Forwarded-Proto https to $cv_forwarded_https = on (default empty)'
        );
        $mapPos = strpos($conf, '$cv_forwarded_https');
        $serverPos = strpos($conf, 'server {');
        Assert::true($mapPos !== false && $serverPos !== false && $mapPos < $serverPos, 'map must be in http context, before server');

        $fastcgiLocations = 0;
        foreach (self::locationBlocks($conf) as $block) {
            if (!str_contains($block, 'fastcgi_pass')) {
                continue;
            }
            $fastcgiLocations++;
            Assert::true(
                str_contains($block, 'fastcgi_param HTTPS $cv_forwarded_https if_not_empty;'),
                'location with fastcgi_pass lacks HTTPS param: ' . strtok($block, '{')
            );
        }
        Assert::true($fastcgiLocations >= 3, 'expected the fastcgi locations of default.conf');
        Assert::same(
            substr_count($conf, 'fastcgi_pass'),
            substr_count($conf, 'fastcgi_param HTTPS $cv_forwarded_https if_not_empty;'),
            'one HTTPS param per fastcgi_pass'
        );
    }

    public function testComposeMountsTrustedProxiesAndPassesTrustForwardedProto(): void
    {
        $compose = self::read('docker-compose.yml');

        Assert::true(
            str_contains(
                $compose,
                '${NGINX_TRUSTED_PROXIES_FILE:-./docker/nginx/trusted-proxies.dev.conf}:/etc/nginx/trusted-proxies.conf:ro'
            ),
            'nginx must mount the trusted proxies file chosen by NGINX_TRUSTED_PROXIES_FILE'
        );
        Assert::true(
            str_contains($compose, 'TRUST_FORWARDED_PROTO: ${TRUST_FORWARDED_PROTO:-false}'),
            'x-php-service environment must pass TRUST_FORWARDED_PROTO'
        );
    }

    public function testEveryComposePortBindsToLoopback(): void
    {
        $lines = preg_split('/\R/', self::read('docker-compose.yml'));
        $inPorts = false;
        $portsIndent = 0;
        $ports = [];
        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            if (preg_match('/^\s*ports:\s*$/', $line) === 1) {
                $inPorts = true;
                $portsIndent = $indent;
                continue;
            }
            if ($inPorts && $indent > $portsIndent && str_starts_with(ltrim($line), '- ')) {
                $ports[] = trim(substr(ltrim($line), 2));
                continue;
            }
            $inPorts = false;
        }

        Assert::true(count($ports) > 0, 'compose must publish at least one port');
        Assert::false(str_contains(self::read('docker-compose.yml'), 'ports: ['), 'ports must use block lists');
        foreach ($ports as $port) {
            Assert::true(str_starts_with($port, '"127.0.0.1:'), 'port must bind to 127.0.0.1: ' . $port);
        }
    }

    public function testEnvExampleDeclaresProxyAndBackupContract(): void
    {
        $env = self::read('.env.example');

        foreach (['TRUST_FORWARDED_PROTO=false', 'BACKUP_PASSPHRASE_FILE=', 'BACKUP_RETENTION_DAYS=14'] as $line) {
            Assert::same(
                1,
                preg_match('/^' . preg_quote($line, '/') . '$/m', $env),
                '.env.example must declare ' . $line
            );
        }
        foreach (['BACKUP_OFFSITE_DIR=', 'BACKUP_OFFSITE_S3_URI=', 'BACKUP_OFFSITE_S3_ENDPOINT='] as $line) {
            Assert::same(1, preg_match('/^' . preg_quote($line, '/') . '$/m', $env), '.env.example must declare ' . $line);
        }
        Assert::same(1, preg_match('/^# BACKUP_DIR=var\/backups$/m', $env), '.env.example must document BACKUP_DIR');
        Assert::same(
            1,
            preg_match('/^#\s*NGINX_TRUSTED_PROXIES_FILE=/m', $env),
            '.env.example must document NGINX_TRUSTED_PROXIES_FILE (commented)'
        );
    }
}
