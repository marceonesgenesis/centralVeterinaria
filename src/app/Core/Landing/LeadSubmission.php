<?php

declare(strict_types=1);

namespace CentralVet\Landing;

/**
 * Lead validado da landing pública. Nome, preço do plano e versão do
 * consentimento vêm de LandingCatalog, nunca do payload.
 *
 * Mensagens em pt no Core por decisão do plano: a landing é pública, só pt,
 * e o `lead.php` não carrega o Adianti.
 */
final class LeadSubmission
{
    /** Limite do texto bruto do phone: o JS envia só dígitos; barra payload forjado. */
    public const PHONE_RAW_MAX = 20;

    private function __construct(
        public readonly string $name,
        public readonly string $clinic,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $vets,
        public readonly string $city,
        public readonly string $uf,
        public readonly string $planId,
        public readonly string $planName,
        public readonly int $planPriceCents,
        public readonly string $consentVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws LeadValidationException com todos os erros juntos
     */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        $name = self::text($payload, 'name');
        if (!self::plainText($name, 3, 120)) {
            $errors['name'] = 'Informe seu nome.';
        }

        $clinic = self::text($payload, 'clinic');
        if (!self::plainText($clinic, 2, 160)) {
            $errors['clinic'] = 'Informe o nome da clínica.';
        }

        $email = self::text($payload, 'email');
        if ($email === null || mb_strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Informe um e-mail válido.';
        }

        $phoneRaw = self::text($payload, 'phone');
        $phone = $phoneRaw === null ? '' : (string) preg_replace('/\D/', '', $phoneRaw);
        if (
            $phoneRaw === null
            || mb_strlen($phoneRaw) > self::PHONE_RAW_MAX
            || strlen($phone) < 10
            || strlen($phone) > 13
        ) {
            $errors['phone'] = 'Informe o WhatsApp com DDD.';
        }

        $vets = self::text($payload, 'vets');
        if ($vets === null || !array_key_exists($vets, LandingCatalog::VETS_OPTIONS)) {
            $errors['vets'] = 'Escolha o número de veterinários.';
        }

        // city e uf são opcionais: ausentes contam como vazio.
        $city = self::text($payload, 'city', '');
        if (!self::plainText($city, 0, 80)) {
            $errors['city'] = 'Informe a cidade com até 80 caracteres, sem < ou >.';
        }

        $uf = self::text($payload, 'uf', '');
        if ($uf === null || ($uf !== '' && !in_array($uf, LandingCatalog::UFS, true))) {
            $errors['uf'] = 'Escolha uma UF válida.';
        }

        $planId = self::text($payload, 'plan');
        $plan = $planId === null ? null : LandingCatalog::plan($planId);
        if ($plan === null) {
            $errors['plan'] = 'Escolha um plano.';
        }

        if (($payload['consent'] ?? null) !== true) {
            $errors['consent'] = 'Marque a autorização de contato para enviar.';
        }

        if ($errors !== []) {
            throw new LeadValidationException($errors);
        }

        return new self(
            (string) $name,
            (string) $clinic,
            (string) $email,
            $phone,
            (string) $vets,
            (string) $city,
            (string) $uf,
            $plan['id'],
            $plan['name'],
            $plan['price_cents'],
            LandingCatalog::CONSENT_VERSION,
        );
    }

    /**
     * Valor sem espaços nem caracteres invisíveis (`\s`, `\p{Z}`, `\p{Cf}`) nas
     * pontas, ou null quando não é string (ou não é UTF-8 válido).
     */
    private static function text(array $payload, string $field, ?string $missing = null): ?string
    {
        if (!array_key_exists($field, $payload)) {
            return $missing;
        }

        $value = $payload[$field];
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return (string) preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $value);
    }

    private static function plainText(?string $value, int $min, int $max): bool
    {
        if ($value === null) {
            return false;
        }

        $length = mb_strlen($value);

        return $length >= $min
            && $length <= $max
            && strpbrk($value, '<>') === false
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 0;
    }
}
