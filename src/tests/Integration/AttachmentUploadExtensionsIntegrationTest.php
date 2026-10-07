<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Presentation\UploadedTmpFile;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Rodada 3, T-20 (correção 2): sem `extensions` na URL o CvUploaderService
 * aceita só UploadedTmpFile::DEFAULT_EXTENSIONS. SystemMessageForm e
 * SystemSupportForm declaram a lista própria no TMultiFile de anexos
 * (escritório: doc, docx, xls, xlsx, odt, ods, gif, zip), sem html/xhtml/svg/php/js.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global).
 */
final class AttachmentUploadExtensionsIntegrationTest
{
    private const FORMS = ['SystemMessageForm', 'SystemSupportForm'];

    /**
     * @return array<string, mixed> classe do form → extensões do campo attachments
     */
    private function attachmentExtensions(): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'foreach (' . var_export(self::FORMS, true) . ' as $class) {'
            . '  $page = new $class([]);'
            . '  $form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '  $field = $form->getField("attachments");'
            . '  $out[$class] = (new ReflectionProperty($field, "extensions"))->getValue($field);'
            . '}'
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'form subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testMessageAndSupportAttachmentsDeclareOfficeExtensionsButNoMarkup(): void
    {
        $allowed = array_merge(UploadedTmpFile::DEFAULT_EXTENSIONS, ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'csv', 'txt', 'gif', 'zip']);
        $refused = ['html', 'htm', 'xhtml', 'svg', 'php', 'js', 'phtml'];

        foreach ($this->attachmentExtensions() as $class => $extensions) {
            Assert::true(is_array($extensions), "{$class}: attachments must declare setAllowedExtensions");

            foreach ($allowed as $ext) {
                Assert::true(UploadedTmpFile::extensionAllowed("anexo.{$ext}", $extensions), "{$class}: .{$ext} must be accepted");
            }

            foreach ($refused as $ext) {
                Assert::false(UploadedTmpFile::extensionAllowed("anexo.{$ext}", $extensions), "{$class}: .{$ext} must be refused");
            }
        }
    }
}
