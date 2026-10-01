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
