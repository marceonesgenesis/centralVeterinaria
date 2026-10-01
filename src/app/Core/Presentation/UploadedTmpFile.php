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
 *  - o nome aparado (espaços; o byte nulo não é aparado) é vazio, `.` ou `..`, contém `..`, `/`, `\` ou byte nulo,
 *    ou difere de basename();
 *  - o arquivo não existe, não é arquivo regular ou o caminho real fica fora
 *    de tmpDir (symlink para fora).
 */
final class UploadedTmpFile
{
    private const INVALID = 'Invalid file';
    private const MAX_SANITIZED = 120;
    private const MAX_ORIGINAL = 255;
    public const MAX_SESSION_UPLOADS = 50;
    private const UTF8_CHAR = '/[\x09\x0A\x0D\x20-\x7E]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/';
    private const PREFIX_PATTERN = '/^[0-9a-f]{32}-/';
    // espaços das pontas saem; o byte nulo não (fica no nome e é recusado)
    private const TRIM_CHARS = " \t\n\r\x0B";

    /** Extensões aceitas pelo uploader quando a URL não traz `extensions` (T-20). */
    public const DEFAULT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv'];

    private function __construct()
    {
    }

    /**
     * Extensão (minúscula) do nome original está em $requested (T-20).
     * $requested null (sem `extensions` na URL) = DEFAULT_EXTENSIONS.
     *
     * @param list<string>|null $requested
     */
    public static function extensionAllowed(string $originalName, ?array $requested): bool
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === '') {
            return false;
        }

        $allowed = array_map(static fn ($e): string => strtolower((string) $e), $requested ?? self::DEFAULT_EXTENSIONS);

        return in_array($ext, $allowed, true);
    }

    /**
     * Tipo real do arquivo (finfo FILEINFO_MIME_TYPE) está em $allowedMimes (T-20).
     * Arquivo ilegível ou inexistente = false.
     *
     * @param list<string> $allowedMimes
     */
    public static function mimeAllowed(string $path, array $allowedMimes): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && in_array($mime, $allowedMimes, true);
    }

    /**
     * Nome imprevisível para gravar um upload em tmp/ (T-63):
     * `<32 hex aleatórios>-<basename saneado>`. O saneamento troca cada byte
     * fora de `[A-Za-z0-9_.-]` por `_`, junta pontos repetidos (resolve()
     * recusa `..`) e tira os pontos das pontas. A parte saneada tem no
     * máximo 120 caracteres, e a extensão é preservada. Se não sobra nada,
     * o nome vira `upload`.
     */
    public static function generateName(string $originalName): string
    {
        $base = basename(str_replace('\\', '/', $originalName));
        $clean = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '_', $base);
        $clean = trim((string) preg_replace('/\.{2,}/', '.', $clean), '.');

        if ($clean === '') {
            $clean = 'upload';
        }

        if (strlen($clean) > self::MAX_SANITIZED) {
            $dot = strrpos($clean, '.');
            $ext = $dot === false ? '' : substr($clean, $dot);

            if ($ext === '' || strlen($ext) >= self::MAX_SANITIZED) {
                $clean = substr($clean, 0, self::MAX_SANITIZED);
            } else {
                $clean = rtrim(substr($clean, 0, self::MAX_SANITIZED - strlen($ext)), '.') . $ext;
            }
        }

        return bin2hex(random_bytes(16)) . '-' . $clean;
    }

    /**
     * Nome para mostrar ao usuário e gravar como nome original: tira o
     * prefixo `<32 hex>-` de generateName().
     */
    public static function displayName(string $name): string
    {
        return (string) preg_replace(self::PREFIX_PATTERN, '', $name);
    }

    /**
     * Nome original de um upload como texto puro (T-63, correção 1): só o
     * basename (`/` e `\\`), sem bytes UTF-8 inválidos nem caracteres de
     * controle, aparado e com no máximo 255 caracteres. Pode voltar vazio.
     * Serve para exibição (com escape) e para original_name/Content-Disposition,
     * nunca para caminho.
     */
    public static function cleanOriginalName(string $raw): string
    {
        $base = basename(str_replace('\\', '/', $raw));
        preg_match_all(self::UTF8_CHAR, $base, $chars);
        $text = str_replace(["\t", "\n", "\r"], '', implode('', $chars[0]));
        // controles C1 e marcas de direção (U+202A–202E, U+2066–2069) não entram
        $text = (string) preg_replace('/[\x{80}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);

        return mb_substr(trim($text), 0, self::MAX_ORIGINAL, 'UTF-8');
    }

    /**
     * Registro da sessão (T-63, correção 1): nome em disco → nome original.
     * Acrescenta o par no fim; acima de $max, o mais antigo sai. Entradas que
     * não são par string → string (lista antiga) são descartadas.
     *
     * @param array<mixed, mixed> $uploads
     *
     * @return array<string, string>
     */
    public static function rememberUpload(array $uploads, string $diskName, string $originalName, int $max = self::MAX_SESSION_UPLOADS): array
    {
        $map = self::sessionMap($uploads);
        unset($map[$diskName]);
        $map[$diskName] = $originalName;

        return array_slice($map, -$max, null, true);
    }

    /**
     * Nome original de um nome em disco do registro; fora dele (ou vazio),
     * o próprio nome sem o prefixo hex.
     *
     * @param array<mixed, mixed> $uploads
     */
    public static function originalName(string $diskName, array $uploads): string
    {
        $original = self::sessionMap($uploads)[$diskName] ?? '';

        return $original !== '' ? $original : self::displayName($diskName);
    }

    /**
     * Só os pares string → string do registro.
     *
     * @param array<mixed, mixed> $uploads
     *
     * @return array<string, string>
     */
    public static function sessionMap(array $uploads): array
    {
        $map = [];

        foreach ($uploads as $disk => $original) {
            if (is_string($disk) && is_string($original)) {
                $map[$disk] = $original;
            }
        }

        return $map;
    }

    /**
     * resolve() restrito aos nomes que a própria sessão enviou (T-63):
     * tmp/ é compartilhado, e um nome previsível de outra sessão (dump ou
     * export de admin) não pode ser anexado.
     *
     * @param list<string> $sessionUploads nomes gerados pelo CvUploaderService nesta sessão
     */
    public static function resolveForSession(string $name, array $sessionUploads, ?string $tmpDir = null): string
    {
        // aparado uma vez: o mesmo nome vai a resolve() e à comparação
        $name = trim($name, self::TRIM_CHARS);
        $real = self::resolve($name, $tmpDir);
        $session = array_map(static fn ($n): string => trim((string) $n, self::TRIM_CHARS), $sessionUploads);

        if (!in_array($name, $session, true)) {
            throw new InvalidArgumentException(self::INVALID);
        }

        return $real;
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

        $name = trim($name, self::TRIM_CHARS);

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
     * Com $sessionUploads (T-63), o nome também precisa estar na lista da
     * sessão (resolveForSession()).
     *
     * @param array<int|string, mixed> $items
     * @param list<string>|null        $sessionUploads
     *
     * @return list<string>
     */
    public static function newUploadItems(array $items, ?string $tmpDir = null, ?array $sessionUploads = null): array
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

            if ($sessionUploads === null) {
                self::resolve($name, $tmpDir);
            } else {
                self::resolveForSession($name, $sessionUploads, $tmpDir);
            }

            $kept[] = $item;
        }

        return $kept;
    }
}
