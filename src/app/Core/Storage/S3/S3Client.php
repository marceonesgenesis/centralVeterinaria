<?php

declare(strict_types=1);

namespace CentralVet\Storage\S3;

use CentralVet\Storage\Exception\StorageException;

/**
 * Minimal S3-compatible HTTP client (path-style addressing:
 * {endpoint}/{bucket}/{key}), hand-rolled with cURL + S3SignatureV4 instead
 * of a full AWS SDK — see S3SignatureV4 for why. Works against MinIO (the
 * optional local docker-compose profile) and any other S3-compatible
 * provider that supports SigV4 and path-style buckets. Virtual-hosted-style
 * addressing is out of scope; it is not needed by MinIO or by this
 * project's local-only usage.
 */
final class S3Client
{
    public function __construct(private readonly S3ClientConfig $config)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(S3ClientConfig::fromEnvironment());
    }

    /** @return array{etag: ?string, version_id: ?string} */
    public function putObject(string $key, string $body, string $contentType): array
    {
        $response = $this->request('PUT', $key, $body, ['Content-Type' => $contentType]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw StorageException::fromResponse('PUT', $key, $response['status']);
        }

        return [
            'etag' => self::extractHeader($response['headers'], 'etag'),
            'version_id' => self::extractHeader($response['headers'], 'x-amz-version-id'),
        ];
    }

    public function getObject(string $key): string
    {
        $response = $this->request('GET', $key, '');

        if ($response['status'] === 404) {
            throw StorageException::notFound($key);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw StorageException::fromResponse('GET', $key, $response['status']);
        }

        return $response['body'];
    }

    /** @return array{content_length: int, content_type: ?string, etag: ?string, version_id: ?string}|null */
    public function headObject(string $key): ?array
    {
        $response = $this->request('HEAD', $key, '');

        if ($response['status'] === 404) {
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw StorageException::fromResponse('HEAD', $key, $response['status']);
        }

        return [
            'content_length' => (int) (self::extractHeader($response['headers'], 'content-length') ?? '0'),
            'content_type' => self::extractHeader($response['headers'], 'content-type'),
            'etag' => self::extractHeader($response['headers'], 'etag'),
            'version_id' => self::extractHeader($response['headers'], 'x-amz-version-id'),
        ];
    }

    public function deleteObject(string $key): void
    {
        $response = $this->request('DELETE', $key, '');

        if ($response['status'] !== 404 && ($response['status'] < 200 || $response['status'] >= 300) && $response['status'] !== 204) {
            throw StorageException::fromResponse('DELETE', $key, $response['status']);
        }
    }

    public function presignedGetUrl(string $key, int $ttlSeconds): string
    {
        $host = $this->hostHeader();
        $canonicalUri = $this->canonicalUri($key);

        $signer = new S3SignatureV4($this->config->accessKey, $this->config->secretKey, $this->config->region);
        $query = $signer->presignQuery('GET', $host, $canonicalUri, $ttlSeconds);

        return sprintf('%s%s?%s', $this->config->endpoint, $canonicalUri, $query);
    }

    /** @param array<string, string> $extraHeaders */
    private function request(string $method, string $key, string $body, array $extraHeaders = []): array
    {
        $host = $this->hostHeader();
        $canonicalUri = $this->canonicalUri($key);
        $payloadHash = hash('sha256', $body);

        $signer = new S3SignatureV4($this->config->accessKey, $this->config->secretKey, $this->config->region);
        $signedHeaders = $signer->signRequest($method, $host, $canonicalUri, $extraHeaders, $payloadHash);

        $headers = array_merge($extraHeaders, $signedHeaders, ['Host' => $host]);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $url = $this->config->endpoint . $canonicalUri;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->config->timeoutSeconds,
            CURLOPT_NOBODY => $method === 'HEAD',
        ]);

        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw StorageException::transport($method, $key, $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => self::parseHeaders(substr($raw, 0, $headerSize)),
            'body' => substr($raw, $headerSize),
        ];
    }

    private function hostHeader(): string
    {
        $parts = parse_url($this->config->endpoint);

        return ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function canonicalUri(string $key): string
    {
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));

        return '/' . rawurlencode($this->config->bucket) . '/' . $encodedKey;
    }

    /** @return array<string, string> */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (explode("\r\n", trim($raw)) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private static function extractHeader(array $headers, string $name): ?string
    {
        $value = $headers[strtolower($name)] ?? null;

        return $value === null ? null : trim($value, '"');
    }
}
