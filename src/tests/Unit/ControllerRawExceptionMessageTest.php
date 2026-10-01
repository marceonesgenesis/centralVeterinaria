<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Rodada 3, T-17: trava de regressão contra `new TMessage(…, $e->getMessage())`
 * nos controllers do produto. Os catches mostram `CvFormat::userError($e)`;
 * a mensagem crua da exceção vai só para o error_log.
 *
 * Lê cada arquivo inteiro com o tokenizer, então pega também a chamada
 * quebrada em várias linhas e ignora comentários. Escopo: `app/control/clinic`,
 * `app/control/SearchBox.php` e `app/control/log`; o template (`admin/`,
 * `communication/`) fica fora por decisão do usuário.
 */
final class ControllerRawExceptionMessageTest
{
    private const SCOPE = ['clinic', 'SearchBox.php', 'log'];

    public function testClinicControllersNeverShowRawExceptionMessages(): void
    {
        $control = dirname(__DIR__, 2) . '/app/control';
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFiles($control) as $path) {
            $scanned++;
            foreach ($this->rawMessageLines((string) file_get_contents($path)) as $line) {
                $offenders[] = substr($path, strlen($control) + 1) . ':' . $line;
            }
        }

        Assert::true($scanned > 0, 'no controller scanned under ' . $control);
        Assert::same(
            [],
            $offenders,
            'new TMessage(...) must not receive getMessage(); use CvFormat::userError($e): ' . implode(', ', $offenders)
        );
    }

    /** @return list<string> */
    private function phpFiles(string $control): array
    {
        $files = [];
        foreach (self::SCOPE as $entry) {
            $path = $control . '/' . $entry;
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            Assert::true(is_dir($path), "{$path} must exist");
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Linhas de cada `new TMessage(` cujo argumento, até o `;`, chama getMessage().
     *
     * @return list<int>
     */
    private function rawMessageLines(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $lines = [];
        $count = count($tokens);

        for ($i = 0; $i < $count - 1; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW || !$this->isTMessage($tokens[$i + 1])) {
                continue;
            }
            for ($j = $i + 2; $j < $count && $tokens[$j] !== ';'; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && strcasecmp($tokens[$j][1], 'getMessage') === 0) {
                    $lines[] = $tokens[$i][2];
                    break;
                }
            }
        }

        return $lines;
    }

    private function isTMessage(mixed $token): bool
    {
        if (!is_array($token)) {
            return false;
        }

        return in_array(ltrim($token[1], '\\'), ['TMessage', 'Adianti\\Widget\\Dialog\\TMessage'], true);
    }
}
