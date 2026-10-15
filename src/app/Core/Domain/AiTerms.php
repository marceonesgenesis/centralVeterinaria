<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Short consent term accepted by the tenant admin before turning the AI
 * assistant on. Changing the text requires a new VERSION (v2 added the
 * voice dictation declaration; tenants that accepted v1 must accept again).
 */
final class AiTerms
{
    public const VERSION = '2026-10-v2';

    private const TEXTS = [
        'pt' => 'Ao ativar o assistente de IA, a clínica autoriza o envio ao provedor Anthropic de dados clínicos '
            . 'minimizados do paciente (espécie, raça, sexo, idade, peso, anamnese, histórico e resultados de exame), '
            . 'sem nome, contato ou documento do tutor. A IA não decide nada: ela apenas sugere textos, e o profissional '
            . 'revisa, edita ou descarta cada sugestão antes de qualquer registro. Cada uso fica registrado em '
            . 'ai_interaction (finalidade, modelo, tokens e custo). O assistente pode ser desligado a qualquer momento.'
            . ' O ditado por voz usa o reconhecimento de voz do navegador (ex.: Google, Apple, Microsoft): o áudio é '
            . 'enviado a esse serviço, não ao Central Vet; quem preferir pode digitar o texto.',
        'en' => 'By turning the AI assistant on, the clinic authorizes sending minimized patient clinical data '
            . '(species, breed, sex, age, weight, anamnesis, history and exam results) to the provider Anthropic, '
            . 'without the tutor\'s name, contact or document. The AI decides nothing: it only suggests text, and the '
            . 'professional reviews, edits or discards every suggestion before anything is recorded. Each use is logged '
            . 'in ai_interaction (purpose, model, tokens and cost). The assistant can be turned off at any time.'
            . ' Voice dictation uses the speech recognition of the browser (e.g. Google, Apple, Microsoft): the audio '
            . 'is sent to that service, not to Central Vet; anyone who prefers can type the text.',
    ];

    private function __construct()
    {
    }

    /** Locales other than `pt` fall back to `en`. */
    public static function text(string $locale): string
    {
        return self::TEXTS[$locale] ?? self::TEXTS['en'];
    }
}
