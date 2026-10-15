<?php

declare(strict_types=1);

namespace CentralVet\Ai;

use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiPurpose;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Versioned prompts of the three Fase 8A use cases. The user content carries
 * only clinical data (species, breed, sex, age, weight and clinical texts),
 * every text passed through PiiMinimizer with the patient and tutor names.
 * Ids, clinic, contacts and the birth date are never sent (the age is).
 *
 * Draft prescriptions and exam results pending review are sent marked as
 * provisional / not validated, and the system prompt says so.
 *
 * Any change to a prompt text must bump its version (`.v1` → `.v2`);
 * AiPromptCatalogTest::testPromptTextIsPinnedToItsVersion pins the hashes.
 */
final class AiPromptCatalog
{
    public const VERSIONS = [
        AiPurpose::PATIENT_SUMMARY => 'patient_summary.v2',
        AiPurpose::ANAMNESIS_ORGANIZE => 'anamnesis_organize.v2',
        AiPurpose::EXAM_SUMMARY => 'exam_summary.v2',
    ];

    private const BASE = [
        'pt' => "Você é um assistente de documentação clínica para médico-veterinário.\n"
            . "Regras:\n"
            . "- Não faça diagnóstico, não prescreva e não sugira conduta.\n"
            . "- Use só os fatos presentes no texto recebido; não invente nem complete informações.\n"
            . "- Quando uma seção não tiver informação, escreva \"não informado\".\n"
            . "- Responda em português do Brasil, em texto simples, com seções curtas e sem markdown.\n"
            . "- Não inclua dados pessoais (nomes, contatos ou documentos); mantenha marcadores como [NOME] como estão.\n"
            . "- Itens marcados como provisórios ou não validados podem mudar: cite-os como tal e não os trate como confirmados.\n",
        'en' => "You are a clinical documentation assistant for a veterinarian.\n"
            . "Rules:\n"
            . "- Do not diagnose, do not prescribe and do not suggest treatment.\n"
            . "- Use only the facts present in the text you receive; do not invent or complete information.\n"
            . "- When a section has no information, write \"not informed\".\n"
            . "- Answer in English, as plain text with short sections and no markdown.\n"
            . "- Do not include personal data (names, contacts or documents); keep markers such as [NOME] as they are.\n"
            . "- Items marked as provisional or not validated may change: mention them as such and do not treat them as confirmed.\n",
    ];

    private const TASKS = [
        AiPurpose::PATIENT_SUMMARY => [
            'pt' => "Tarefa: resuma o paciente para o atendimento atual a partir dos dados clínicos e do histórico.\n"
                . "Seções, nesta ordem: Identificação clínica; Histórico relevante; Medicações recentes; Exames recentes; Pontos de atenção registrados.",
            'en' => "Task: summarize the patient for the current encounter from the clinical data and the history.\n"
                . "Sections, in this order: Clinical identification; Relevant history; Recent medications; Recent exams; Recorded points of attention.",
        ],
        AiPurpose::ANAMNESIS_ORGANIZE => [
            'pt' => "Tarefa: organize o texto livre da anamnese nas seções abaixo, sem acrescentar fato que não está no texto.\n"
                . "Seções, nesta ordem: Queixa principal; Histórico da queixa; Alimentação e manejo; Sinais relatados pelo tutor; Medicações em uso; Outras informações.",
            'en' => "Task: organize the free-text anamnesis into the sections below, without adding any fact that is not in the text.\n"
                . "Sections, in this order: Chief complaint; History of the complaint; Diet and husbandry; Signs reported by the owner; Current medications; Other information.",
        ],
        AiPurpose::EXAM_SUMMARY => [
            'pt' => "Tarefa: resuma o laudo do exame sem interpretar além do que o laudo informa.\n"
                . "Seções, nesta ordem: Exame; Achados principais; Valores fora da referência informados no laudo; Observações.",
            'en' => "Task: summarize the exam report without interpreting beyond what the report states.\n"
                . "Sections, in this order: Exam; Main findings; Out-of-reference values stated in the report; Notes.",
        ],
    ];

    private const LABELS = [
        'pt' => [
            'patient' => 'Dados clínicos do paciente',
            'species' => 'Espécie',
            'breed' => 'Raça',
            'sex' => 'Sexo',
            'age' => 'Idade',
            'weight' => 'Peso',
            'missing' => 'não informado',
            'M' => 'Macho',
            'F' => 'Fêmea',
            'U' => 'não informado',
            'years' => ['ano', 'anos'],
            'months' => ['mês', 'meses'],
            'and' => 'e',
            'current_anamnesis' => 'Anamnese do atendimento atual',
            'previous_encounters' => 'Atendimentos anteriores',
            'encounter_on' => 'Atendimento de',
            'anamnesis' => 'Anamnese',
            'physical_exam' => 'Exame físico',
            'diagnosis' => 'Diagnóstico',
            'plan' => 'Plano clínico',
            'prescriptions' => 'Prescrições recentes',
            'prescription_on' => 'Prescrição de',
            'exams' => 'Exames recentes',
            'free_text' => 'Texto livre da anamnese',
            'exam' => 'Exame',
            'received_at' => 'Recebido em',
            'report' => 'Laudo',
            'none' => 'nenhum registro',
            'provisional_prescription' => '(rascunho, não emitida — provisória)',
            'unvalidated_exam' => '(pendente de revisão — não validado)',
        ],
        'en' => [
            'patient' => 'Patient clinical data',
            'species' => 'Species',
            'breed' => 'Breed',
            'sex' => 'Sex',
            'age' => 'Age',
            'weight' => 'Weight',
            'missing' => 'not informed',
            'M' => 'Male',
            'F' => 'Female',
            'U' => 'not informed',
            'years' => ['year', 'years'],
            'months' => ['month', 'months'],
            'and' => 'and',
            'current_anamnesis' => 'Current encounter anamnesis',
            'previous_encounters' => 'Previous encounters',
            'encounter_on' => 'Encounter on',
            'anamnesis' => 'Anamnesis',
            'physical_exam' => 'Physical exam',
            'diagnosis' => 'Diagnosis',
            'plan' => 'Clinical plan',
            'prescriptions' => 'Recent prescriptions',
            'prescription_on' => 'Prescription on',
            'exams' => 'Recent exams',
            'free_text' => 'Free-text anamnesis',
            'exam' => 'Exam',
            'received_at' => 'Received on',
            'report' => 'Report',
            'none' => 'no records',
            'provisional_prescription' => '(draft, not issued — provisional)',
            'unvalidated_exam' => '(pending review — not validated)',
        ],
    ];

    private function __construct()
    {
    }

    public static function version(string $purpose): string
    {
        AiPurpose::assertValid($purpose);

        return self::VERSIONS[$purpose];
    }

    /**
     * @param array<string, mixed> $context `['encounter' => ..., 'history' => ...]` (patient_summary),
     *        `['encounter' => ..., 'free_text' => string]` (anamnesis_organize) or `['exam' => ...]` (exam_summary)
     * @param DateTimeImmutable|null $today reference date for the age (UTC today by default)
     */
    public static function build(string $purpose, array $context, string $locale, ?DateTimeImmutable $today = null): AiCompletionRequest
    {
        $version = self::version($purpose);
        $lang = AiLocale::fromLanguage($locale);
        $today ??= new DateTimeImmutable('today', new DateTimeZone('UTC'));

        $userContent = match ($purpose) {
            AiPurpose::PATIENT_SUMMARY => self::patientSummary($context, $lang, $today),
            AiPurpose::ANAMNESIS_ORGANIZE => self::anamnesis($context, $lang, $today),
            AiPurpose::EXAM_SUMMARY => self::examSummary($context, $lang, $today),
        };

        $system = self::BASE[$lang] . "\n" . self::TASKS[$purpose][$lang];

        return new AiCompletionRequest($purpose, $version, $system, $userContent);
    }

    /** @param array<string, mixed> $context */
    private static function patientSummary(array $context, string $lang, DateTimeImmutable $today): string
    {
        $encounter = self::requireArray($context, 'encounter');
        $history = self::requireArray($context, 'history');
        $names = self::names($encounter);
        $l = self::LABELS[$lang];

        $lines = self::patientBlock($encounter, $lang, $today, true);
        $lines[] = '';
        $lines[] = $l['current_anamnesis'] . ':';
        $lines[] = self::text($encounter['anamnesis_text'] ?? null, $names, $lang);

        $lines[] = '';
        $lines[] = $l['previous_encounters'] . ':';
        $encounters = is_array($history['encounters'] ?? null) ? $history['encounters'] : [];
        if ($encounters === []) {
            $lines[] = $l['none'];
        }
        foreach ($encounters as $previous) {
            $lines[] = '- ' . $l['encounter_on'] . ' ' . self::date($previous['started_at'] ?? null, $lang);
            foreach (['anamnesis_text' => 'anamnesis', 'physical_exam_text' => 'physical_exam', 'diagnosis_text' => 'diagnosis', 'clinical_plan_text' => 'plan'] as $key => $label) {
                $lines[] = '  ' . $l[$label] . ': ' . self::text($previous[$key] ?? null, $names, $lang);
            }
        }

        $lines[] = '';
        $lines[] = $l['prescriptions'] . ':';
        $prescriptions = is_array($history['prescriptions'] ?? null) ? $history['prescriptions'] : [];
        if ($prescriptions === []) {
            $lines[] = $l['none'];
        }
        foreach ($prescriptions as $prescription) {
            $lines[] = '- ' . $l['prescription_on'] . ' ' . self::date($prescription['created_at'] ?? null, $lang)
                . (($prescription['status'] ?? null) === 'draft' ? ' ' . $l['provisional_prescription'] : '');
            foreach ((array) ($prescription['items'] ?? []) as $item) {
                $lines[] = '  * ' . self::text(is_scalar($item) ? (string) $item : null, $names, $lang);
            }
        }

        $lines[] = '';
        $lines[] = $l['exams'] . ':';
        $exams = is_array($history['exam_results'] ?? null) ? $history['exam_results'] : [];
        if ($exams === []) {
            $lines[] = $l['none'];
        }
        foreach ($exams as $exam) {
            $lines[] = '- ' . self::text($exam['exam_name'] ?? null, $names, $lang) . ' (' . self::date($exam['received_at'] ?? null, $lang) . ')'
                . self::unvalidatedMark($exam, $lang) . ': '
                . self::text($exam['result_text'] ?? null, $names, $lang);
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $context */
    private static function anamnesis(array $context, string $lang, DateTimeImmutable $today): string
    {
        $encounter = self::requireArray($context, 'encounter');
        if (!isset($context['free_text']) || !is_string($context['free_text'])) {
            throw new InvalidArgumentException('AI prompt context is incomplete');
        }

        $lines = self::patientBlock($encounter, $lang, $today, true);
        $lines[] = '';
        $lines[] = self::LABELS[$lang]['free_text'] . ':';
        $lines[] = self::text($context['free_text'], self::names($encounter), $lang);

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $context */
    private static function examSummary(array $context, string $lang, DateTimeImmutable $today): string
    {
        $exam = self::requireArray($context, 'exam');
        $names = self::names($exam);
        $l = self::LABELS[$lang];

        $lines = self::patientBlock($exam, $lang, $today, false);
        $lines[] = '';
        $lines[] = $l['exam'] . ': ' . self::text($exam['exam_name'] ?? null, $names, $lang) . self::unvalidatedMark($exam, $lang);
        $lines[] = $l['received_at'] . ': ' . self::date($exam['received_at'] ?? null, $lang);
        $lines[] = $l['report'] . ':';
        $lines[] = self::text($exam['result_text'] ?? null, $names, $lang);

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $subject
     * @return list<string>
     */
    private static function patientBlock(array $subject, string $lang, DateTimeImmutable $today, bool $withWeight): array
    {
        $l = self::LABELS[$lang];
        $names = self::names($subject);
        $sex = is_string($subject['sex'] ?? null) && isset($l[$subject['sex']]) && in_array($subject['sex'], ['M', 'F', 'U'], true)
            ? $l[$subject['sex']]
            : $l['missing'];

        $lines = [
            $l['patient'] . ':',
            '- ' . $l['species'] . ': ' . self::text($subject['species'] ?? null, $names, $lang),
            '- ' . $l['breed'] . ': ' . self::text($subject['breed'] ?? null, $names, $lang),
            '- ' . $l['sex'] . ': ' . $sex,
            '- ' . $l['age'] . ': ' . self::age($subject['birth_date'] ?? null, $lang, $today),
        ];

        if ($withWeight) {
            $weight = $subject['weight_kg'] ?? null;
            $lines[] = '- ' . $l['weight'] . ': ' . (is_numeric($weight) ? self::number((string) $weight) . ' kg' : $l['missing']);
        }

        return $lines;
    }

    private static function age(mixed $birthDate, string $lang, DateTimeImmutable $today): string
    {
        $l = self::LABELS[$lang];
        if (!is_string($birthDate) || preg_match('/^\d{4}-\d{2}-\d{2}/', $birthDate) !== 1) {
            return $l['missing'];
        }

        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', substr($birthDate, 0, 10), new DateTimeZone('UTC'));
        $reference = DateTimeImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'), new DateTimeZone('UTC'));
        if ($birth === false || $reference === false || $birth > $reference) {
            return $l['missing'];
        }

        $diff = $birth->diff($reference);
        $years = $diff->y . ' ' . $l['years'][$diff->y === 1 ? 0 : 1];
        $months = $diff->m . ' ' . $l['months'][$diff->m === 1 ? 0 : 1];

        if ($diff->y === 0) {
            return $months;
        }

        return $diff->m === 0 ? $years : $years . ' ' . $l['and'] . ' ' . $months;
    }

    /** @param array<string, mixed> $exam */
    private static function unvalidatedMark(array $exam, string $lang): string
    {
        return ($exam['pending_review'] ?? false) === true ? ' ' . self::LABELS[$lang]['unvalidated_exam'] : '';
    }

    private static function number(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }

    private static function date(mixed $value, string $lang): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return self::LABELS[$lang]['missing'];
    }

    /** @param list<string|null> $names */
    private static function text(?string $value, array $names, string $lang): string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return self::LABELS[$lang]['missing'];
        }

        return PiiMinimizer::minimize($value, $names);
    }

    /**
     * @param array<string, mixed> $subject
     * @return list<string|null>
     */
    private static function names(array $subject): array
    {
        return [
            is_string($subject['patient_name'] ?? null) ? $subject['patient_name'] : null,
            is_string($subject['tutor_name'] ?? null) ? $subject['tutor_name'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function requireArray(array $context, string $key): array
    {
        if (!isset($context[$key]) || !is_array($context[$key])) {
            throw new InvalidArgumentException('AI prompt context is incomplete');
        }

        return $context[$key];
    }
}
