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

        foreach (['ok.pdf', 'link.pdf', 'dump.zip', 'abc-ok.pdf'] as $name) {
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

    public function testResolveForSessionOnlyAcceptsNamesUploadedByThisSession(): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'dump.zip', 'dump de admin');
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'abc-ok.pdf', '%PDF-1.4 abc');

        $this->assertInvalidForSession('dump.zip', []);
        $this->assertInvalidForSession('dump.zip', ['outro.pdf']);
        $this->assertInvalidForSession('../dump.zip', ['../dump.zip']);
        $this->assertInvalidForSession('nao-existe.pdf', ['nao-existe.pdf']);
        $this->assertInvalidForSession('abc-ok.pdf', ['ABC-OK.PDF']);

        Assert::same(
            realpath($this->dir . DIRECTORY_SEPARATOR . 'abc-ok.pdf'),
            UploadedTmpFile::resolveForSession('abc-ok.pdf', ['abc-ok.pdf'], $this->dir)
        );
    }

    public function testGeneratedNamesAreUnpredictableSanitizedAndKeepTheExtension(): void
    {
        $first = UploadedTmpFile::generateName('laudo ção.pdf');
        $second = UploadedTmpFile::generateName('laudo ção.pdf');

        Assert::true(preg_match('/^[0-9a-f]{32}-laudo_+o_?\.pdf$/', $first) === 1, "unexpected name {$first}");
        Assert::true(str_ends_with($first, '.pdf'), 'extension kept');
        Assert::true($first !== $second, 'two calls give different names');

        Assert::true(preg_match('/^[0-9a-f]{32}-upload$/', UploadedTmpFile::generateName('')) === 1, 'empty name');
        Assert::true(preg_match('/^[0-9a-f]{32}-upload$/', UploadedTmpFile::generateName('...')) === 1, 'only dots');
        Assert::true(preg_match('/^[0-9a-f]{32}-passwd$/', UploadedTmpFile::generateName('../../etc/passwd')) === 1, 'basename only');

        $long = UploadedTmpFile::generateName(str_repeat('a', 300) . '.csv');
        Assert::same(33 + 120, strlen($long), 'sanitized part has at most 120 chars');
        Assert::true(str_ends_with($long, '.csv'), 'extension kept after truncation');

        foreach (['a..b.pdf', 'x/../y.pdf', " sp .pdf", "nul\0.pdf"] as $raw) {
            $name = UploadedTmpFile::generateName($raw);
            file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, 'x');
            try {
                Assert::same(
                    realpath($this->dir . DIRECTORY_SEPARATOR . $name),
                    UploadedTmpFile::resolveForSession($name, [$name], $this->dir),
                    'generated name is accepted by resolve()'
                );
            } finally {
                unlink($this->dir . DIRECTORY_SEPARATOR . $name);
            }
        }
    }

    public function testDisplayNameDropsTheRandomPrefix(): void
    {
        Assert::same('laudo.pdf', UploadedTmpFile::displayName(str_repeat('ab', 16) . '-laudo.pdf'));
        Assert::same('laudo.pdf', UploadedTmpFile::displayName('laudo.pdf'));
        Assert::same('ABCDEF-x.pdf', UploadedTmpFile::displayName('ABCDEF-x.pdf'));
    }

    public function testNewUploadItemsWithSessionListRejectsNamesFromOtherSessions(): void
    {
        $item = urlencode(json_encode(['idFile' => '', 'fileName' => 'tmp/ok.pdf', 'newFile' => 'tmp/ok.pdf']));

        Assert::same([$item], UploadedTmpFile::newUploadItems([$item, ''], $this->dir, ['ok.pdf']));

        foreach ([[], ['outro.pdf']] as $uploads) {
            try {
                UploadedTmpFile::newUploadItems([$item], $this->dir, $uploads);
            } catch (InvalidArgumentException $e) {
                Assert::same('Invalid file', $e->getMessage());

                continue;
            }

            Assert::true(false, 'newUploadItems must reject a name outside the session list');
        }
    }

    public function testSessionRegistryKeepsTheOriginalUtf8NameNextToTheSanitizedDiskName(): void
    {
        $disk = UploadedTmpFile::generateName('Relatório.pdf');
        Assert::true(preg_match('/^[0-9a-f]{32}-Relat_+rio\.pdf$/', $disk) === 1, "disk name stays sanitized: {$disk}");

        $uploads = UploadedTmpFile::rememberUpload([], $disk, 'Relatório.pdf');

        Assert::same([$disk => 'Relatório.pdf'], $uploads);
        Assert::same('Relatório.pdf', UploadedTmpFile::originalName($disk, $uploads));
        Assert::same([$disk], array_keys($uploads), 'the keys are the names accepted by resolveForSession');

        // nome fora do registro: cai no nome em disco sem o prefixo
        Assert::same('outro.pdf', UploadedTmpFile::originalName(str_repeat('0', 32) . '-outro.pdf', $uploads));
    }

    public function testOriginalNameIsPlainTextNeverAPath(): void
    {
        Assert::same('passwd', UploadedTmpFile::cleanOriginalName('../../etc/passwd'));
        Assert::same('laudo.pdf', UploadedTmpFile::cleanOriginalName('C:\\docs\\laudo.pdf'));
        Assert::same('ab.pdf', UploadedTmpFile::cleanOriginalName("a\r\nb\0.pdf"));
        Assert::same('Exame ção.pdf', UploadedTmpFile::cleanOriginalName('  Exame ção.pdf  '));
        Assert::same('', UploadedTmpFile::cleanOriginalName("\xff\xfe"));
        Assert::same(255, mb_strlen(UploadedTmpFile::cleanOriginalName(str_repeat('é', 300))));
    }

    public function testSessionRegistryKeepsAtMostFiftyEntriesDroppingTheOldest(): void
    {
        $uploads = [];
        for ($i = 0; $i < 61; $i++) {
            $uploads = UploadedTmpFile::rememberUpload($uploads, "n{$i}", "original {$i}");
        }

        Assert::same(50, count($uploads));
        Assert::same('n11', array_key_first($uploads));
        Assert::same('n60', array_key_last($uploads));

        // lista antiga (só nomes, sem par) é descartada
        Assert::same(['x' => 'y'], UploadedTmpFile::rememberUpload(['legado.pdf', 3 => 'z'], 'x', 'y'));
    }

    private function assertInvalidForSession(string $name, array $uploads): void
    {
        $label = json_encode([$name, $uploads]);

        try {
            UploadedTmpFile::resolveForSession($name, $uploads, $this->dir);
        } catch (InvalidArgumentException $e) {
            Assert::same('Invalid file', $e->getMessage(), "message for {$label}");

            return;
        }

        Assert::true(false, "resolveForSession({$label}) must throw InvalidArgumentException('Invalid file')");
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
