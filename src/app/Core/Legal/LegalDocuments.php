<?php

declare(strict_types=1);

namespace CentralVet\Legal;

/**
 * Documentos legais vigentes (Termos de Uso e Política de Privacidade).
 *
 * As versões são a prova do aceite registrado por usuário (Etapa 5 T-10):
 * mudar o texto de um HTML em `app/view/legal/` exige nova versão aqui.
 * Os textos são rascunho até a revisão jurídica (`DRAFT_MARKER`).
 */
final class LegalDocuments
{
    public const TERMS = 'terms';
    public const PRIVACY = 'privacy';

    public const TERMS_VERSION = 'termos-uso-2026-10';
    public const PRIVACY_VERSION = 'privacidade-2026-10';

    public const TERMS_URL = 'legal.php?doc=termos';
    public const PRIVACY_URL = 'legal.php?doc=privacidade';

    public const DRAFT_MARKER = 'RASCUNHO — revisar com jurídico';

    /**
     * @return array{terms: string, privacy: string}
     */
    public static function currentVersions(): array
    {
        return [
            self::TERMS => self::TERMS_VERSION,
            self::PRIVACY => self::PRIVACY_VERSION,
        ];
    }
}
