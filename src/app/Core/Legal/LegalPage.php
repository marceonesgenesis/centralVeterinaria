<?php

declare(strict_types=1);

namespace CentralVet\Legal;

/**
 * Página pública dos documentos legais (`legal.php?doc=termos|privacidade`).
 *
 * O documento sai de uma lista fechada de templates estáticos; qualquer outro
 * valor de `doc` responde 404, sem montar caminho a partir da entrada.
 */
final class LegalPage
{
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public const NOT_FOUND_BODY = 'Documento não encontrado';

    /** @var array<string, string> doc da URL => template relativo a `src/` */
    private const TEMPLATES = [
        'termos' => 'app/view/legal/termos-de-uso.html',
        'privacidade' => 'app/view/legal/politica-de-privacidade.html',
    ];

    /**
     * @return array{status: int, body: string}
     */
    public static function render(string $doc): array
    {
        $template = self::TEMPLATES[$doc] ?? null;
        if ($template === null) {
            return self::notFound();
        }

        $body = @file_get_contents(dirname(__DIR__, 3) . '/' . $template);
        if (!is_string($body) || $body === '') {
            return self::notFound();
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * @return array{status: int, body: string}
     */
    private static function notFound(): array
    {
        $body = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<title>' . self::NOT_FOUND_BODY . '</title></head><body><p>'
            . self::NOT_FOUND_BODY . '</p></body></html>';

        return ['status' => 404, 'body' => $body];
    }
}
