<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UploadedTmpFile;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;
use InvalidArgumentException;

/**
 * Rodada 2, T-62: o nome de arquivo vindo da requisição só vira caminho
 * dentro de tmp/. Travessia (`../`), separadores, caminho absoluto, nome
 * vazio, `..`, arquivo inexistente e symlink para fora lançam 'Invalid file'.
 *
 * Usa um tmpDir próprio em sys_get_temp_dir(), com `ok.pdf` dentro e
 * `fora.txt` no diretório pai; tudo é removido no tearDown.
 */
final class UploadedTmpFileTest
{
    private string $root = '';
    private string $dir = '';

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cv-t62-' . uniqid('', true);
        $this->dir = $this->root . DIRECTORY_SEPARATOR . 'tmp';
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'ok.pdf', '%PDF-1.4 ok');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'fora.txt', 'fora');
    }

    public function tearDown(): void
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return;
        }

        foreach (['ok.pdf', 'link.pdf'] as $name) {
            $path = $this->dir . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        $outside = $this->root . DIRECTORY_SEPARATOR . 'fora.txt';
        if (is_file($outside)) {
            unlink($outside);
        }

        rmdir($this->root);
    }

    public function testFileInsideTmpDirResolvesToItsRealPath(): void
    {
        $expected = realpath($this->dir . DIRECTORY_SEPARATOR . 'ok.pdf');

        Assert::same($expected, UploadedTmpFile::resolve('ok.pdf', $this->dir));
        Assert::same($expected, UploadedTmpFile::resolve('  ok.pdf  ', $this->dir), 'name is trimmed');
    }

    public function testTraversalSeparatorsAndBogusNamesAreRejected(): void
    {
        $outside = $this->root . DIRECTORY_SEPARATOR . 'fora.txt';
        $cases = ['../fora.txt', 'a/b', 'a\\b', $outside, '', '   ', '.', '..', 'x..y.pdf', "ok.pdf\0.txt", 'nao-existe.pdf', '../tmp/ok.pdf'];

        foreach ($cases as $name) {
            $this->assertInvalid($name);
        }
    }

    public function testTmpDirItselfMustExist(): void
    {
        $this->assertInvalid('ok.pdf', $this->root . DIRECTORY_SEPARATOR . 'nao-existe');
    }

    public function testSymlinkPointingOutsideTmpDirIsRejected(): void
    {
        $link = $this->dir . DIRECTORY_SEPARATOR . 'link.pdf';

        if (!@symlink($this->root . DIRECTORY_SEPARATOR . 'fora.txt', $link)) {
            throw new SkippedTestException('symlink() is not available in this environment');
        }

        $this->assertInvalid('link.pdf');
    }

    public function testNewUploadItemsInsideTmpDirAreKeptAndEmptyOnesDropped(): void
    {
        $item = urlencode(json_encode(['idFile' => '', 'fileName' => 'tmp/ok.pdf', 'newFile' => 'tmp/ok.pdf']));

        Assert::same([$item], UploadedTmpFile::newUploadItems([$item, '', '  '], $this->dir));
        Assert::same([], UploadedTmpFile::newUploadItems([], $this->dir));
    }

    public function testFileHandlingItemsOutsideTmpOrDeletingAreRejected(): void
    {
        $outside = $this->root . DIRECTORY_SEPARATOR . 'fora.txt';
        $json = static fn (array $item): string => urlencode((string) json_encode($item));
        $cases = [
            'delFile of a real file' => $json(['fileName' => 'tmp/ok.pdf', 'newFile' => 'tmp/ok.pdf', 'delFile' => $outside]),
            'delFile only' => $json(['fileName' => 'tmp/ok.pdf', 'delFile' => 'tmp/ok.pdf']),
            'stored path without newFile' => $json(['fileName' => 'app/config/application.php']),
            'traversal in fileName' => $json(['fileName' => 'tmp/../fora.txt', 'newFile' => 'tmp/../fora.txt']),
            'absolute fileName' => $json(['fileName' => $outside, 'newFile' => $outside]),
            'fileName without tmp/ prefix' => $json(['fileName' => 'ok.pdf', 'newFile' => 'ok.pdf']),
            'newFile differs from fileName' => $json(['fileName' => 'tmp/ok.pdf', 'newFile' => 'tmp/outro.pdf']),
            'missing file' => $json(['fileName' => 'tmp/nao-existe.pdf', 'newFile' => 'tmp/nao-existe.pdf']),
            'not json' => 'tmp/ok.pdf',
            'json scalar' => $json(['tmp/ok.pdf']),
        ];

        foreach ($cases as $label => $item) {
            try {
                UploadedTmpFile::newUploadItems(['', $item], $this->dir);
            } catch (InvalidArgumentException $e) {
                Assert::same('Invalid file', $e->getMessage(), "message for {$label}");

                continue;
            }

            Assert::true(false, "newUploadItems must reject: {$label}");
        }

        Assert::true(is_file($outside), 'nothing is deleted by the validation');
    }

    private function assertInvalid(string $name, ?string $dir = null): void
    {
        $dir ??= $this->dir;
        $label = json_encode($name);

        try {
            UploadedTmpFile::resolve($name, $dir);
        } catch (InvalidArgumentException $e) {
            Assert::same('Invalid file', $e->getMessage(), "message for {$label}");

            return;
        }

        Assert::true(false, "resolve({$label}) must throw InvalidArgumentException('Invalid file')");
    }
}
