<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\DocumentContentFactory;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeDocumentSourceQuery;
use CentralVet\Tests\Support\FakeSenderNamesQuery;
use DateTimeImmutable;

/**
 * Unit tests for DocumentContentFactory (T-11): neutral content per kind
 * (vaccination card, prescription, medical certificate, surgery consent),
 * sender names, subject lines and `source_not_found` when a source is gone.
 */
final class DocumentContentFactoryTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 7;
    private const TUTOR_ID = 10;
    private const PATIENT_ID = 20;
    private const MISSING_PATIENT_ID = 21;
    private const PRESCRIPTION_ID = 30;
    private const SURGERY_ID = 40;
    private const NOW = '2026-10-06 10:00:00';

    private FakeDocumentSourceQuery $sources;

    public function setUp(): void
    {
        $this->sources = new FakeDocumentSourceQuery();
        $this->sources->seedPatient([
            'patient_id' => self::PATIENT_ID,
            'patient_name' => 'F7B teste Rex',
            'species' => 'Cão',
            'breed' => 'Labrador',
            'tutor_id' => self::TUTOR_ID,
            'tutor_name' => 'F7B teste Tutor',
        ]);
        $this->sources->seedVaccinations(self::PATIENT_ID, [
            [
                'vaccine_name' => 'V10',
                'dose_number' => 1,
                'applied_at' => '2026-01-15 09:30:00',
                'lot' => 'L-1',
                'next_dose_at' => '2026-02-15',
                'professional_name' => 'Dra. Ana',
            ],
            [
                'vaccine_name' => 'Antirrábica',
                'dose_number' => 1,
                'applied_at' => '2026-03-01 11:00:00',
                'lot' => null,
                'next_dose_at' => null,
                'professional_name' => 'Dr. Bruno',
            ],
        ]);
        $this->sources->seedPrescription([
            'prescription_id' => self::PRESCRIPTION_ID,
            'patient_id' => self::PATIENT_ID,
            'system_unit_id' => self::UNIT_ID,
            'professional_name' => 'Dra. Ana',
            'orientation_text' => "Dar após a refeição.\nRetornar em 7 dias.",
            'created_at' => '2026-10-01 10:00:00',
            'items' => [
                [
                    'medication_name' => 'Amoxicilina',
                    'dose' => '250',
                    'dose_unit' => 'mg',
                    'route' => 'oral',
                    'frequency' => '12/12h',
                    'duration' => '7 dias',
                ],
            ],
        ]);
        $this->sources->seedSurgery([
            'surgery_id' => self::SURGERY_ID,
            'patient_id' => self::PATIENT_ID,
            'system_unit_id' => self::UNIT_ID,
            'procedure_name' => 'Orquiectomia',
            'scheduled_start_at' => '2026-10-10 08:00:00',
            'surgeon_name' => 'Dr. Bruno',
            'consent_signer_name' => 'F7B teste Tutor',
            'consent_text' => 'Autorizo o procedimento.',
            'consent_recorded_at' => '2026-10-05 15:00:00',
        ]);
    }

    private function factory(): DocumentContentFactory
    {
        return new DocumentContentFactory(
            $this->sources,
            new FakeSenderNamesQuery([self::UNIT_ID => ['unit_name' => 'Unidade Centro', 'clinic_name' => 'Clínica F7B']]),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );
    }

    private static function document(string $kind, int $sourceId, ?string $bodyText = null, int $patientId = self::PATIENT_ID): GeneratedDocument
    {
        return GeneratedDocument::request(
            self::TENANT_ID,
            self::UNIT_ID,
            $patientId,
            self::TUTOR_ID,
            $kind,
            $sourceId,
            null,
            $bodyText,
            false,
            self::USER_ID,
        );
    }

    public function testVaccinationCardHasOneRowPerDose(): void
    {
        $content = $this->factory()->build(self::document(DocumentKind::VACCINATION_CARD, self::PATIENT_ID));

        Assert::same('Carteira de vacinação', $content->title);
        Assert::same('Clínica F7B', $content->clinicName);
        Assert::same('Unidade Centro', $content->unitName);
        Assert::same(['Vacina', 'Dose', 'Aplicação', 'Lote', 'Próxima dose', 'Profissional'], $content->tableHeader);
        Assert::count(2, $content->tableRows);
        Assert::same(['V10', '1', '15/01/2026', 'L-1', '15/02/2026', 'Dra. Ana'], $content->tableRows[0]);
        Assert::same(['Antirrábica', '1', '01/03/2026', '', '', 'Dr. Bruno'], $content->tableRows[1]);
        Assert::same(
            ['Paciente: F7B teste Rex', 'Espécie: Cão (Labrador)', 'Tutor: F7B teste Tutor'],
            $content->subjectLines,
        );
        Assert::null($content->signatureName);
        Assert::same(self::NOW, $content->issuedAt->format('Y-m-d H:i:s'));
    }

    public function testPrescriptionSignedByProfessional(): void
    {
        $content = $this->factory()->build(self::document(DocumentKind::PRESCRIPTION, self::PRESCRIPTION_ID));

        Assert::same('Dra. Ana', $content->signatureName);
        Assert::same(['Medicamento', 'Dose', 'Via', 'Frequência', 'Duração'], $content->tableHeader);
        Assert::same([['Amoxicilina', '250 mg', 'oral', '12/12h', '7 dias']], $content->tableRows);
        Assert::same(['Dar após a refeição.', 'Retornar em 7 dias.'], $content->paragraphs);
    }

    public function testMedicalCertificateUsesBodyLinesAndBlankSignature(): void
    {
        $content = $this->factory()->build(self::document(
            DocumentKind::MEDICAL_CERTIFICATE,
            self::PATIENT_ID,
            "Atesto que o paciente foi atendido.\n\nRepouso por 3 dias.",
        ));

        Assert::same(['Atesto que o paciente foi atendido.', 'Repouso por 3 dias.'], $content->paragraphs);
        Assert::same([], $content->tableHeader);
        Assert::null($content->signatureName);
    }

    public function testSurgeryConsentUsesSnapshotText(): void
    {
        $content = $this->factory()->build(self::document(
            DocumentKind::SURGERY_CONSENT,
            self::SURGERY_ID,
            "F7B teste Tutor\n\nAutorizo o procedimento.",
        ));

        Assert::same(['Autorizo o procedimento.'], $content->paragraphs);
        Assert::same('F7B teste Tutor', $content->signatureName);
        Assert::same('Procedimento: Orquiectomia', $content->subjectLines[3]);
        Assert::same('Data prevista: 10/10/2026 08:00', $content->subjectLines[4]);
    }

    public function testMissingSenderNamesBecomeEmpty(): void
    {
        $factory = new DocumentContentFactory($this->sources, new FakeSenderNamesQuery([]));
        $content = $factory->build(self::document(DocumentKind::VACCINATION_CARD, self::PATIENT_ID));

        Assert::same('', $content->clinicName);
        Assert::same('', $content->unitName);
    }

    public function testMissingPatientFailsWithSourceNotFound(): void
    {
        $this->assertSourceNotFound(self::document(DocumentKind::VACCINATION_CARD, self::MISSING_PATIENT_ID, null, self::MISSING_PATIENT_ID));
    }

    public function testMissingPrescriptionOrSurgeryFailsWithSourceNotFound(): void
    {
        $this->assertSourceNotFound(self::document(DocumentKind::PRESCRIPTION, 999));
        $this->assertSourceNotFound(self::document(DocumentKind::SURGERY_CONSENT, 999, "X\n\nY"));
    }

    private function assertSourceNotFound(GeneratedDocument $document): void
    {
        $caught = null;

        try {
            $this->factory()->build($document);
        } catch (DocumentGenerationFailed $exception) {
            $caught = $exception;
        }

        Assert::notNull($caught, 'Expected DocumentGenerationFailed');
        Assert::same(DocumentGenerationFailed::SOURCE_NOT_FOUND, $caught->errorCode());
    }
}
