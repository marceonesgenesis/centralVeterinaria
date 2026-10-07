<?php

declare(strict_types=1);

namespace CentralVet\Landing;

/**
 * Contrato HTTP de `POST /lead.php` (landing pública). Corpo JSON com os
 * campos de FIELDS; token de formulário no header TOKEN_HEADER.
 *
 * Respostas (JSON):
 * - 201 {"accepted":true}
 * - 200 {"accepted":true}                                   honeypot preenchido
 * - 400 {"accepted":false,"error":"invalid_request"}
 * - 403 {"accepted":false,"error":"invalid_token"}
 * - 403 {"accepted":false,"error":"forbidden_origin"}
 * - 405 {"accepted":false,"error":"method_not_allowed"}
 * - 413 {"accepted":false,"error":"payload_too_large"}
 * - 422 {"accepted":false,"error":"validation","fields":{"email":"Informe um e-mail válido."}}
 * - 429 {"accepted":false,"error":"rate_limited","retry_after":60}
 * - 503 {"accepted":false,"error":"unavailable"}
 */
final class LeadEndpoint
{
    public const PATH = '/lead.php';

    public const TOKEN_HEADER = 'X-CV-Lead-Token';

    public const MAX_BODY_BYTES = 8192;

    public const HONEYPOT_FIELD = 'website';

    public const FIELDS = ['name', 'clinic', 'email', 'phone', 'vets', 'city', 'uf', 'plan', 'consent', 'website'];

    private function __construct()
    {
    }
}
