<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Rodada 4, T-03: os controllers de anexos (foto do paciente, anexos do
 * atendimento e resultado de exame) gravam e leem pela StorageFactory, com o
 * driver configurável, em vez de S3CompatibleStorage::fromEnvironment.
 *
 * Lê as fontes: os controllers Adianti só carregam com init.php.
 */
final class AttachmentStorageWiringTest
{
    private const CLINIC = '/app/control/clinic/';

    public function testNoControllerOrApplicationServiceBuildsS3StorageDirectly(): void
    {
        $offenders = [];
        foreach (['/app/control', '/app/Core/Application'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->src() . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'S3CompatibleStorage::fromEnvironment')) {
                    $offenders[] = substr($file->getPathname(), strlen($this->src()));
                }
            }
        }

        Assert::same([], $offenders, 'S3CompatibleStorage::fromEnvironment must not appear in: ' . implode(', ', $offenders));
    }

    public function testPatientFormUsesPatientPhotoStorageTwice(): void
    {
        Assert::same(2, substr_count($this->controller('PatientForm.php'), 'StorageFactory::forPatientPhotos('), 'PatientForm::onPhoto and buildPatientService use StorageFactory::forPatientPhotos');
    }

    public function testEncounterViewWritesAndReadsThroughFactory(): void
    {
        $source = $this->controller('EncounterView.php');

        Assert::stringContains('StorageFactory::forWrites(', $source, 'EncounterView writes through StorageFactory::forWrites');
        Assert::stringContains('StorageFactory::forProvider(', $source, 'EncounterView reads each attachment through StorageFactory::forProvider');
    }

    public function testExamResultFormWritesThroughFactory(): void
    {
        Assert::stringContains('StorageFactory::forWrites(', $this->controller('ExamResultForm.php'), 'ExamResultForm writes through StorageFactory::forWrites');
    }

    private function controller(string $file): string
    {
        return (string) file_get_contents($this->src() . self::CLINIC . $file);
    }

    private function src(): string
    {
        return dirname(__DIR__, 2);
    }
}
