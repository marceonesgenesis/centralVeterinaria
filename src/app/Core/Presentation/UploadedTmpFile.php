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

    /**
     * Itens de um TMultiFile com enableFileHandling() (JSON urlencoded, como
     * o AdiantiFileSaveTrait os lê) de um formulário que só envia uploads
     * novos (T-62, correção 1). Remove os itens vazios e devolve os demais sem
     * alteração. Cada item precisa ter `fileName` igual a `newFile`, no formato
     * `tmp/<nome>`, com `<nome>` aceito por resolve(), e nenhum `delFile`. O
     * trait faz unlink() do delFile e rename() do fileName sem contenção.
     * Qualquer outro item lança 'Invalid file' antes de o trait rodar.
     *
     * @param array<int|string, mixed> $items
     *
     * @return list<string>
     */
    public static function newUploadItems(array $items, ?string $tmpDir = null): array
    {
        $kept = [];

        foreach ($items as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException(self::INVALID);
            }

            if (trim($item) === '') {
                continue;
            }

            $data = json_decode(urldecode($item));

            if (
                !is_object($data)
                || !empty($data->delFile)
                || !isset($data->fileName, $data->newFile)
                || !is_string($data->fileName)
                || $data->fileName !== $data->newFile
                || !str_starts_with($data->fileName, 'tmp/')
            ) {
                throw new InvalidArgumentException(self::INVALID);
            }

            $name = substr($data->fileName, 4);

            // o trait usa o fileName literal: sem espaços nas pontas
            if ($name !== trim($name)) {
                throw new InvalidArgumentException(self::INVALID);
            }

            self::resolve($name, $tmpDir);
            $kept[] = $item;
        }

        return $kept;
    }
}
