<?php

declare(strict_types=1);

namespace CentralVet\Operations;

use RuntimeException;

/**
 * Cifra autenticada dos backups da hospedagem compartilhada (Etapa 4, T-17),
 * onde não há `gpg`: só a extensão openssl e `hash_pbkdf2`.
 *
 * Formato `CVBK1`: `CVBK1` + salt (16 bytes) + IV (16 bytes) + AES-256-CTR do
 * conteúdo, em blocos de 1 MiB (o contador avança 65536 blocos AES por
 * bloco) + HMAC-SHA256 (32 bytes) sobre cabeçalho e cifra. As chaves de
 * cifra e de MAC são as duas metades de
 * `hash_pbkdf2('sha256', $passphrase, $salt, 200000, 64, true)`.
 *
 * `decryptFile` confere o HMAC no arquivo inteiro antes de gravar qualquer
 * byte: com frase errada ou byte alterado, lança `backup authentication
 * failed` e `$output` não é criado. A memória fica em ~1 MiB por bloco.
 */
final class BackupCipher
{
    public const MAGIC = 'CVBK1';

    private const SALT_BYTES = 16;
    private const IV_BYTES = 16;
    private const MAC_BYTES = 32;
    private const CHUNK_BYTES = 1048576;
    private const PBKDF2_ITERATIONS = 200000;
    private const CIPHER = 'aes-256-ctr';

    private function __construct()
    {
    }

    public static function encryptFile(string $input, string $output, string $passphrase): void
    {
        self::assertPassphrase($passphrase);
        $in = self::open($input, 'rb');
        $partial = $output . '.part';
        $out = self::open($partial, 'xb');

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $iv = random_bytes(self::IV_BYTES);
            [$encKey, $macKey] = self::keys($passphrase, $salt);
            $header = self::MAGIC . $salt . $iv;
            $mac = hash_init('sha256', HASH_HMAC, $macKey);
            hash_update($mac, $header);
            self::write($out, $header);

            $counter = $iv;
            while (!feof($in)) {
                $chunk = stream_get_contents($in, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('backup read failed');
                }
                if ($chunk === '') {
                    break;
                }
                $cipher = self::crypt($chunk, $encKey, $counter, true);
                hash_update($mac, $cipher);
                self::write($out, $cipher);
                $counter = self::advance($counter, intdiv(self::CHUNK_BYTES, 16));
            }

            self::write($out, hash_final($mac, true));
            fclose($out);
            $out = null;
            if (!rename($partial, $output)) {
                throw new RuntimeException('backup write failed');
            }
        } finally {
            fclose($in);
            if ($out !== null) {
                fclose($out);
            }
            if (is_file($partial)) {
                @unlink($partial);
            }
        }
    }

    public static function decryptFile(string $input, string $output, string $passphrase): void
    {
        self::assertPassphrase($passphrase);
        $size = filesize($input);
        $headerBytes = strlen(self::MAGIC) + self::SALT_BYTES + self::IV_BYTES;
        if ($size === false || $size < $headerBytes + self::MAC_BYTES) {
            throw new RuntimeException('backup authentication failed');
        }

        $in = self::open($input, 'rb');

        try {
            $header = (string) fread($in, $headerBytes);
            if (strlen($header) !== $headerBytes || !str_starts_with($header, self::MAGIC)) {
                throw new RuntimeException('backup authentication failed');
            }
            $salt = substr($header, strlen(self::MAGIC), self::SALT_BYTES);
            $iv = substr($header, strlen(self::MAGIC) + self::SALT_BYTES, self::IV_BYTES);
            [$encKey, $macKey] = self::keys($passphrase, $salt);
            $cipherBytes = $size - $headerBytes - self::MAC_BYTES;

            $mac = hash_init('sha256', HASH_HMAC, $macKey);
            hash_update($mac, $header);
            self::eachChunk($in, $cipherBytes, static function (string $chunk) use ($mac): void {
                hash_update($mac, $chunk);
            });
            $tag = (string) fread($in, self::MAC_BYTES);
            if (!hash_equals(hash_final($mac, true), $tag)) {
                throw new RuntimeException('backup authentication failed');
            }

            fseek($in, $headerBytes);
            $partial = $output . '.part';
            $out = self::open($partial, 'xb');
            try {
                $counter = $iv;
                self::eachChunk($in, $cipherBytes, static function (string $chunk) use ($out, $encKey, &$counter): void {
                    self::write($out, self::crypt($chunk, $encKey, $counter, false));
                    $counter = self::advance($counter, intdiv(self::CHUNK_BYTES, 16));
                });
                fclose($out);
                $out = null;
                if (!rename($partial, $output)) {
                    throw new RuntimeException('backup write failed');
                }
            } finally {
                if ($out !== null) {
                    fclose($out);
                }
                if (is_file($partial)) {
                    @unlink($partial);
                }
            }
        } finally {
            fclose($in);
        }
    }

    /** @return array{0: string, 1: string} */
    private static function keys(string $passphrase, string $salt): array
    {
        $material = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 64, true);

        return [substr($material, 0, 32), substr($material, 32, 32)];
    }

    /** @param \Closure(string): void $consume */
    private static function eachChunk($in, int $length, \Closure $consume): void
    {
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = stream_get_contents($in, min(self::CHUNK_BYTES, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('backup authentication failed');
            }
            $remaining -= strlen($chunk);
            $consume($chunk);
        }
    }

    private static function crypt(string $data, string $key, string $counter, bool $encrypt): string
    {
        $result = $encrypt
            ? openssl_encrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $counter)
            : openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $counter);
        if ($result === false) {
            throw new RuntimeException('backup cipher failed');
        }

        return $result;
    }

    /** Soma $blocks ao contador CTR de 128 bits (big-endian). */
    private static function advance(string $counter, int $blocks): string
    {
        $bytes = array_values(unpack('C*', $counter));
        $carry = $blocks;
        for ($i = 15; $i >= 0 && $carry > 0; $i--) {
            $sum = $bytes[$i] + ($carry & 0xFF);
            $bytes[$i] = $sum & 0xFF;
            $carry = ($carry >> 8) + ($sum >> 8);
        }

        return pack('C*', ...$bytes);
    }

    private static function assertPassphrase(string $passphrase): void
    {
        if ($passphrase === '') {
            throw new RuntimeException('backup passphrase is empty');
        }
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException('backup file not accessible');
        }

        return $handle;
    }

    /** @param resource $handle */
    private static function write($handle, string $data): void
    {
        if (fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('backup write failed');
        }
    }
}
