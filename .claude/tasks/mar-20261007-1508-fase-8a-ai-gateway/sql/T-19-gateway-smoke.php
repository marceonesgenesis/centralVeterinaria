<?php

declare(strict_types=1);

/**
 * T-19 — smoke de CLI do AI Gateway (substitui o Roteiro C pela tela; ruling do orquestrador).
 *
 * Faz UMA chamada `patient_summary` com texto clínico fictício (prefixo "F8A teste", sem PII real),
 * montando AiConfig::fromEnvironment() e o gateway pela AiGatewayFactory. Não toca o banco.
 * Imprime só: provider, model, tokens (entrada, saída, cache), custo em micro-USD, stop_reason,
 * latência e os 80 primeiros caracteres da resposta. Nunca imprime a chave, headers nem o request.
 *
 * NÃO EXECUTAR sem decisão do orquestrador (chama a API real e gera custo). Uso sugerido, a partir de
 * /var/www/html/centralvet, com AI_PROVIDER=anthropic e ANTHROPIC_API_KEY no ambiente do app:
 *   docker compose run --rm --no-deps -T \
 *     -v /var/www/html/centralvet/src:/var/www/html/src:ro \
 *     -v /var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-gateway-smoke.php:/tmp/T-19-gateway-smoke.php:ro \
 *     app php /tmp/T-19-gateway-smoke.php
 * Autoload: CV_AUTOLOAD (opcional) ou /var/www/html/src/vendor/autoload.php.
 * Saída 0 = chamada ok; 1 = provedor não é anthropic / gateway não configurado; 2 = erro do provedor.
 */

use CentralVet\Ai\AiConfig;
use CentralVet\Ai\AiGatewayFactory;
use CentralVet\Ai\AiPromptCatalog;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Domain\Exception\AiUnavailableException;

$autoload = getenv('CV_AUTOLOAD') ?: '/var/www/html/src/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "autoload não encontrado: {$autoload}\n");
    exit(1);
}

require $autoload;

$config = AiConfig::fromEnvironment();

if ($config->provider() !== 'anthropic') {
    fwrite(STDERR, 'AI_PROVIDER precisa ser anthropic (atual: ' . $config->provider() . ")\n");
    exit(1);
}

$gateway = AiGatewayFactory::fromConfig($config);

if (!$gateway->isConfigured() || $gateway->providerName() !== 'anthropic') {
    fwrite(STDERR, 'gateway não configurado (provider ' . $gateway->providerName() . ")\n");
    exit(1);
}

// Contexto fictício: nomes com prefixo "F8A teste" (o PiiMinimizer troca por [NOME]); sem e-mail,
// telefone nem documento; ids inexistentes e não enviados ao provedor.
$context = [
    'encounter' => [
        'encounter_id' => 0,
        'system_unit_id' => 0,
        'patient_id' => 0,
        'status' => 'in_progress',
        'anamnesis_text' => 'F8A teste: cão com vômito há 2 dias após troca de ração, apetite reduzido, '
            . 'sem diarreia. Temperatura 38.9 °C, mucosas normocoradas, hidratação adequada.',
        'patient_name' => 'F8A teste Paciente',
        'tutor_name' => 'F8A teste Tutor',
        'species' => 'Canina',
        'breed' => 'SRD',
        'sex' => 'M',
        'birth_date' => '2021-03-01',
        'weight_kg' => '12.40',
    ],
    'history' => [
        'encounters' => [[
            'started_at' => '2026-06-10 10:00:00',
            'anamnesis_text' => 'F8A teste: consulta de rotina, sem queixas.',
            'physical_exam_text' => 'F8A teste: exame físico sem alterações.',
            'diagnosis_text' => 'F8A teste: hígido.',
            'clinical_plan_text' => 'F8A teste: vacinação anual em dia.',
        ]],
        'prescriptions' => [],
        'exam_results' => [[
            'exam_name' => 'Hemograma',
            'received_at' => '2026-06-10 15:00:00',
            'result_text' => 'F8A teste: hematócrito 45%, leucócitos 11.000/µL, plaquetas 280.000/µL.',
        ]],
    ],
];

$request = AiPromptCatalog::build(AiPurpose::PATIENT_SUMMARY, $context, 'pt');

$started = hrtime(true);

try {
    $result = $gateway->complete($request);
} catch (AiProviderException | AiUnavailableException $e) {
    $latencyMs = (int) round((hrtime(true) - $started) / 1e6);
    // Só o código do motivo e o status HTTP; a mensagem nunca carrega chave, headers ou request.
    echo 'erro: ' . (new ReflectionClass($e))->getShortName() . ' reason=' . $e->reason()
        . ($e instanceof AiProviderException ? ' http_status=' . ($e->httpStatus() ?? 'null') : '')
        . ' latency_ms=' . $latencyMs . "\n";
    exit(2);
}

$latencyMs = (int) round((hrtime(true) - $started) / 1e6);
$preview = mb_substr(preg_replace('/\s+/u', ' ', $result->text) ?? '', 0, 80);

printf(
    "provider=%s\nmodel=%s\ninput_tokens=%d\noutput_tokens=%d\ncache_read_tokens=%d\ncache_write_tokens=%d\n"
    . "cost_micro_usd=%s\nstop_reason=%s\nlatency_ms=%d\nresposta_80=%s\n",
    $result->provider,
    $result->model,
    $result->usage->inputTokens,
    $result->usage->outputTokens,
    $result->usage->cacheReadTokens,
    $result->usage->cacheWriteTokens,
    $result->costMicroUsd === null ? 'null' : (string) $result->costMicroUsd,
    $result->stopReason,
    $latencyMs,
    $preview,
);

exit(0);
