<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Document\DocumentHtmlBuilder;
use CentralVet\Document\DompdfDocumentRenderer;
use CentralVet\Domain\Contract\DocumentRendererInterface;
use CentralVet\Domain\DocumentContent;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;

final class DocumentRendererTest
{
    private const SCRIPT = '<script>alert(1)</script>';
    private const IMG = '<img src="http://127.0.0.1:9/x.png">';

    public function testBuildEscapesParagraphsAndSubjectLines(): void
    {
        $html = (new DocumentHtmlBuilder())->build(self::content());

        Assert::stringContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        Assert::stringContains('&lt;img src=&quot;http://127.0.0.1:9/x.png&quot;&gt;', $html);
        Assert::false(str_contains($html, '<script'), 'raw <script> must not reach the HTML');
        Assert::false(str_contains($html, '<img'), 'raw <img> must not reach the HTML');
        Assert::false(str_contains($html, '<link'), 'no <link> is allowed');
    }

    public function testBuildEscapesEveryField(): void
    {
        $html = (new DocumentHtmlBuilder())->build(new DocumentContent(
            '<b>T</b>',
            '<i>C</i>',
            '<u>U</u>',
            ['Paciente: <s>Rex</s>'],
            ["linha 1\nlinha <em>2</em>"],
            ['<th>H</th>'],
            [['<td>v</td>', "O'Neil & \"Co\""]],
            '<sig>',
            new DateTimeImmutable('2026-10-06 14:05:00'),
        ));

        foreach (['<b>', '<i>', '<u>', '<s>', '<em>', '<th>H', '<td>v', '<sig>'] as $raw) {
            Assert::false(str_contains($html, $raw), "raw {$raw} must be escaped");
        }
        Assert::stringContains('O&#039;Neil &amp; &quot;Co&quot;', $html);
        Assert::stringContains('linha 1<br>', $html);
        Assert::stringContains('Emitido em 06/10/2026 14:05', $html);
        Assert::stringContains('DejaVu Sans', $html);
    }

    public function testRenderReturnsPdfBytes(): void
    {
        $renderer = new DompdfDocumentRenderer();
        Assert::instanceOf(DocumentRendererInterface::class, $renderer);

        $bytes = $renderer->render(self::content());

        Assert::same('%PDF-', substr($bytes, 0, 5));
    }

    public function testDompdfOptionsAreLockedDown(): void
    {
        $options = (new DompdfDocumentRenderer())->options();

        Assert::false($options->getIsRemoteEnabled(), 'isRemoteEnabled must be false');
        Assert::false($options->getIsPhpEnabled(), 'isPhpEnabled must be false');
        Assert::false($options->getIsJavascriptEnabled(), 'isJavascriptEnabled must be false');
        Assert::same(sys_get_temp_dir(), $options->getTempDir());
        Assert::true(in_array(sys_get_temp_dir(), $options->getChroot(), true), 'chroot must be the temp dir');
    }

    private static function content(): DocumentContent
    {
        return new DocumentContent(
            'Atestado',
            'Clínica F7B teste',
            'Unidade Centro',
            ['Paciente: ' . self::IMG, 'Tutor: F7B teste'],
            [self::SCRIPT, 'Texto livre'],
            ['Vacina', 'Data'],
            [['V10', '06/10/2026']],
            'Dra. F7B teste',
            new DateTimeImmutable('2026-10-06 14:05:00'),
        );
    }
}
