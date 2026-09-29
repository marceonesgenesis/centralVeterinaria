<?php

declare(strict_types=1);

namespace CentralVet\Storage\S3;

/**
 * Minimal implementation of AWS Signature Version 4, scoped to what this
 * project's S3-compatible adapter needs (single-region "s3" service,
 * path-style requests, a handful of HTTP verbs plus presigned GET).
 *
 * Hand-rolled on purpose: src/composer.json has no AWS/S3 dependency today,
 * and pulling in the full aws-sdk-php just to sign put/get/head/delete
 * would be a heavy addition for a MinIO-compatible adapter. This class only
 * implements the subset of the spec exercised by S3Client.
 */
final class S3SignatureV4
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * Signs a request; returns the extra headers the caller must send
     * (x-amz-content-sha256, x-amz-date, Authorization).
     *
     * @param array<string, string> $headers Extra headers already chosen by the caller (e.g. Content-Type).
     * @return array<string, string>
     */
    public function signRequest(
        string $method,
        string $host,
        string $canonicalUri,
        array $headers,
        string $payloadHash,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $headers['host'] = $host;
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $amzDate;

        [$canonicalHeaders, $signedHeaders] = $this->canonicalizeHeaders($headers);

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $this->region, $this->service);
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $credentialScope,
            $signedHeaders,
            $signature,
        );

        return [
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Authorization' => $authorization,
        ];
    }

    /** Builds the signed query string (X-Amz-*) for a presigned GET URL valid for $ttlSeconds. */
    public function presignQuery(
        string $method,
        string $host,
        string $canonicalUri,
        int $ttlSeconds,
        ?\DateTimeImmutable $now = null,
    ): string {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $credentialScope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $this->region, $this->service);

        $queryParams = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => sprintf('%s/%s', $this->accessKey, $credentialScope),
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, $ttlSeconds),
            'X-Amz-SignedHeaders' => 'host',
        ];

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $this->canonicalizeQuery($queryParams),
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $queryParams['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        return $this->canonicalizeQuery($queryParams);
    }

    private function signingKey(string $dateStamp): string
    {
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $this->service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: string, 1: string} [canonicalHeaders, signedHeaders]
     */
    private function canonicalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = trim((string) $value);
        }

        ksort($normalized);

        $canonical = '';
        foreach ($normalized as $name => $value) {
            $canonical .= "{$name}:{$value}\n";
        }

        return [$canonical, implode(';', array_keys($normalized))];
    }

    /** @param array<string, string> $params */
    private function canonicalizeQuery(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }
}
