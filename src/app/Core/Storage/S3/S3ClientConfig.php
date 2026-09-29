<?php

declare(strict_types=1);

namespace CentralVet\Storage\S3;

/**
 * Connection settings for the S3-compatible adapter. Defaults point at the
 * optional local MinIO service (docker-compose profile "minio") and use
 * placeholder credentials — never a real provider or secret.
 */
final class S3ClientConfig
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $region,
        public readonly string $bucket,
        public readonly string $accessKey,
        public readonly string $secretKey,
        public readonly int $timeoutSeconds = 10,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            rtrim((string) (getenv('S3_ENDPOINT') ?: 'http://minio:9000'), '/'),
            (string) (getenv('S3_REGION') ?: 'us-east-1'),
            (string) (getenv('S3_BUCKET') ?: 'centralvet-local'),
            (string) (getenv('S3_ACCESS_KEY') ?: 'minioadmin'),
            (string) (getenv('S3_SECRET_KEY') ?: 'minioadmin'),
            (int) (getenv('S3_TIMEOUT') ?: 10),
        );
    }
}
