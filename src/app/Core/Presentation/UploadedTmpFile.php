<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

use InvalidArgumentException;

/**
 * Caminho único de um arquivo enviado para tmp/ (rodada 2, T-62). Sem Adianti.
 *
 * O nome chega da requisição (POST `filename`, campo TFile, `$param['file']`)
 * e nunca é concatenado direto em `'tmp/' . $nome`: `../app/config/application.php`
 * leria, anexaria e apagaria um arquivo do servidor. resolve() só devolve o
 * caminho real absoluto de um arquivo regular que está dentro de tmp/.
 *
 * Lança \InvalidArgumentException('Invalid file') (catálogo UserMessage →
 * "Arquivo inválido") quando:
 *  - o tmpDir não existe (realpath falso);
 *  - o nome aparado é vazio, `.` ou `..`, contém `..`, `/`, `\` ou byte nulo,
 *    ou difere de basename();
 *  - o arquivo não existe, não é arquivo regular ou o caminho real fica fora
 *    de tmpDir (symlink para fora).
 */
final class UploadedTmpFile
{
    private const INVALID = 'Invalid file';

    private function __construct()
    {
    }

    /**
     * @param string      $name   nome vindo da requisição (só o nome, sem diretório)
     * @param string|null $tmpDir diretório base; null = realpath('tmp') do diretório de trabalho (src/)
     *
     * @return string caminho real absoluto do arquivo dentro de tmpDir
     */
    public static function resolve(string $name, ?string $tmpDir = null): string
    {
        $base = realpath($tmpDir ?? 'tmp');

        if ($base === false || !is_dir($base)) {
            throw new InvalidArgumentException(self::INVALID);
        }

        $name = trim($name);

        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || str_contains($name, '..')
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || basename($name) !== $name
        ) {
            throw new InvalidArgumentException(self::INVALID);
        }

        $real = realpath($base . DIRECTORY_SEPARATOR . $name);

        if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new InvalidArgumentException(self::INVALID);
        }

        return $real;
    }
}
