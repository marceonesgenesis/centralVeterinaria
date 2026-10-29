<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Operations\BackupCipher;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use RuntimeException;

/**
 * Cifra CVBK1 do backup da hospedagem compartilhada (Etapa 4, T-17):
 * AES-256-CTR em blocos de 1 MiB + HMAC-SHA256, chaves por PBKDF2. Um
 * arquivo de 3 MiB volta igual; frase errada ou byte alterado lançam
 * `backup authentication failed` sem criar o arquivo de saída.
 */
final class BackupCipherTest
{
    private const PASSPHRASE = 'frase-de-teste-etapa4-cvbk1';

    private string $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cv-cipher-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testThreeMebibyteFileRoundTripsWithSameSha256(): void
    {
        $plain = $this->plainFile();
        BackupCipher::encryptFile($plain, $this->dir . '/plain.cvbk', self::PASSPHRASE);
        BackupCipher::decryptFile($this->dir . '/plain.cvbk', $this->dir . '/plain.out', self::PASSPHRASE);

        Assert::same(hash_file('sha256', $plain), hash_file('sha256', $this->dir . '/plain.out'));
        Assert::same(3 * 1024 * 1024 + 5 + 16 + 16 + 32, filesize($this->dir . '/plain.cvbk'));
    }

    public function testEncryptedFileStartsWithMagicAndHidesPlaintext(): void
    {
        $plain = $this->plainFile();
        BackupCipher::encryptFile($plain, $this->dir . '/plain.cvbk', self::PASSPHRASE);

        $head = (string) file_get_contents($this->dir . '/plain.cvbk', false, null, 0, 5);
        Assert::same('CVBK1', BackupCipher::MAGIC);
        Assert::same('CVBK1', $head);
        Assert::false(str_contains((string) file_get_contents($this->dir . '/plain.cvbk'), 'linha-conhecida-do-backup'));
    }

    public function testWrongPassphraseFailsAuthenticationWithoutOutputFile(): void
    {
        BackupCipher::encryptFile($this->plainFile(), $this->dir . '/plain.cvbk', self::PASSPHRASE);

        $this->assertAuthenticationFails($this->dir . '/plain.cvbk', 'frase-errada');
    }

    public function testAlteredByteFailsAuthenticationWithoutOutputFile(): void
    {
        BackupCipher::encryptFile($this->plainFile(), $this->dir . '/plain.cvbk', self::PASSPHRASE);

        $handle = fopen($this->dir . '/plain.cvbk', 'r+b');
        $middle = intdiv((int) filesize($this->dir . '/plain.cvbk'), 2);
        fseek($handle, $middle);
        $byte = fread($handle, 1);
        fseek($handle, $middle);
        fwrite($handle, chr(ord($byte) ^ 0x01));
        fclose($handle);

        $this->assertAuthenticationFails($this->dir . '/plain.cvbk', self::PASSPHRASE);
    }

    private function assertAuthenticationFails(string $input, string $passphrase): void
    {
        $output = $this->dir . '/plain.out';

        try {
            BackupCipher::decryptFile($input, $output, $passphrase);
        } catch (RuntimeException $exception) {
            Assert::same('backup authentication failed', $exception->getMessage());
            Assert::false(file_exists($output), 'decryptFile created the output file');

            return;
        }

        throw new AssertionFailedException('decryptFile accepted a forged or wrongly keyed backup');
    }

    private function plainFile(): string
    {
        $path = $this->dir . '/plain.bin';
        $handle = fopen($path, 'wb');
        for ($i = 0; $i < 3; $i++) {
            $chunk = random_bytes(1024 * 1024 - 64) . str_pad('linha-conhecida-do-backup', 64, '.');
            fwrite($handle, $chunk);
        }
        fclose($handle);

        return $path;
    }
}
