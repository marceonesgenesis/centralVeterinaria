<?php

declare(strict_types=1);

namespace CentralVet\Application;

use Closure;
use CentralVet\Domain\Contract\DocumentContentFactoryInterface;
use CentralVet\Domain\Contract\DocumentSourceQueryInterface;
use CentralVet\Domain\Contract\SenderNamesQueryInterface;
use CentralVet\Domain\DocumentContent;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Domain\GeneratedDocument;
use DateTimeImmutable;
use Exception;

/**
 * Turns the sources of a requested document into neutral content, per kind
 * (Fase 7B, T-11). Labels are pt-BR, like the PDF titles of DocumentKind.
 * A missing source (patient, prescription or surgery) raises
 * `DocumentGenerationFailed(SOURCE_NOT_FOUND)`, which the worker does not
 * retry. Exceptions carry only the code, never personal data.
 */
final class DocumentContentFactory implements DocumentContentFactoryInterface
{
    private const VACCINATION_HEADER = ['Vacina', 'Dose', 'Aplicação', 'Lote', 'Próxima dose', 'Profissional'];
    private const PRESCRIPTION_HEADER = ['Medicamento', 'Dose', 'Via', 'Frequência', 'Duração'];

    private readonly Closure $clock;

    public function __construct(
        private readonly DocumentSourceQueryInterface $sources,
        private readonly SenderNamesQueryInterface $names,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function build(GeneratedDocument $document): DocumentContent
    {
        $patient = $this->sources->patientSummary($document->patientId());

        if ($patient === null) {
            throw new DocumentGenerationFailed(DocumentGenerationFailed::SOURCE_NOT_FOUND);
        }

        $subjectLines = self::subjectLines($patient);
        $paragraphs = [];
        $tableHeader = [];
        $tableRows = [];
        $signatureName = null;

        switch ($document->kind()) {
            case DocumentKind::VACCINATION_CARD:
                $tableHeader = self::VACCINATION_HEADER;

                foreach ($this->sources->vaccinations($document->patientId()) as $dose) {
                    $tableRows[] = [
                        (string) $dose['vaccine_name'],
                        (string) $dose['dose_number'],
                        self::date($dose['applied_at'] ?? null),
                        (string) ($dose['lot'] ?? ''),
                        self::date($dose['next_dose_at'] ?? null),
                        (string) $dose['professional_name'],
                    ];
                }
                break;

            case DocumentKind::PRESCRIPTION:
                $prescription = $this->sources->prescription($document->sourceId());

                if ($prescription === null) {
                    throw new DocumentGenerationFailed(DocumentGenerationFailed::SOURCE_NOT_FOUND);
                }

                $tableHeader = self::PRESCRIPTION_HEADER;

                foreach ($prescription['items'] as $item) {
                    $tableRows[] = [
                        (string) $item['medication_name'],
                        trim($item['dose'] . ' ' . $item['dose_unit']),
                        (string) $item['route'],
                        (string) $item['frequency'],
                        (string) $item['duration'],
                    ];
                }

                $paragraphs = self::lines($prescription['orientation_text'] ?? null);
                $signatureName = (string) $prescription['professional_name'];
                break;

            case DocumentKind::MEDICAL_CERTIFICATE:
                $paragraphs = self::lines($document->bodyText());
                break;

            case DocumentKind::SURGERY_CONSENT:
                $surgery = $this->sources->surgery($document->sourceId());

                if ($surgery === null) {
                    throw new DocumentGenerationFailed(DocumentGenerationFailed::SOURCE_NOT_FOUND);
                }

                $subjectLines[] = 'Procedimento: ' . $surgery['procedure_name'];
                $subjectLines[] = 'Data prevista: ' . self::dateTime($surgery['scheduled_start_at'] ?? null);

                // Snapshot written at request time: signer name, blank line, consent text.
                $lines = self::lines($document->bodyText());
                $signer = array_shift($lines);
                $signatureName = $signer === null || $signer === '' ? null : $signer;
                $paragraphs = $lines;
                break;
        }

        $names = $this->names->namesForUnit($document->systemUnitId());

        return new DocumentContent(
            title: $document->title(),
            clinicName: (string) ($names['clinic_name'] ?? ''),
            unitName: (string) ($names['unit_name'] ?? ''),
            subjectLines: $subjectLines,
            paragraphs: $paragraphs,
            tableHeader: $tableHeader,
            tableRows: $tableRows,
            signatureName: $signatureName,
            issuedAt: ($this->clock)(),
        );
    }

    /**
     * @param array{patient_name: string, species: string, breed: ?string, tutor_name: string} $patient
     * @return list<string>
     */
    private static function subjectLines(array $patient): array
    {
        $species = (string) $patient['species'];
        $breed = trim((string) ($patient['breed'] ?? ''));

        if ($breed !== '') {
            $species .= " ({$breed})";
        }

        return [
            'Paciente: ' . $patient['patient_name'],
            'Espécie: ' . $species,
            'Tutor: ' . $patient['tutor_name'],
        ];
    }

    /** @return list<string> non-empty trimmed lines of a free text */
    private static function lines(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        $lines = array_map('trim', preg_split('/\R/u', $text) ?: []);

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    private static function date(?string $value): string
    {
        return self::format($value, 'd/m/Y');
    }

    private static function dateTime(?string $value): string
    {
        return self::format($value, 'd/m/Y H:i');
    }

    private static function format(?string $value, string $format): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format($format);
        } catch (Exception) {
            return $value;
        }
    }
}
