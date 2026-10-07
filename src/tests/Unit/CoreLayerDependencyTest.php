<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;
use InvalidArgumentException;

/**
 * Rodada 3, T-02: regra de camadas de `app/Core/README.md` — Application,
 * Domain e Persistence não dependem de Presentation. O parser de data e hora
 * vive em `CentralVet\Support\DateTimeInput`; o de Presentation só delega.
 */
final class CoreLayerDependencyTest
{
    private const SUPPORT_PARSER = 'CentralVet\\Support\\DateTimeInput';
    private const PRESENTATION_PARSER = 'CentralVet\\Presentation\\DateTimeInput';

    public function testApplicationDomainAndPersistenceDoNotReferencePresentation(): void
    {
        $core = dirname(__DIR__, 2) . '/app/Core';
        $offenders = [];

        foreach (['Application', 'Domain', 'Persistence'] as $layer) {
            $directory = $core . '/' . $layer;
            Assert::true(is_dir($directory), "{$directory} must exist");

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if (str_contains((string) file_get_contents($file->getPathname()), 'CentralVet\\Presentation\\')) {
                    $offenders[] = $layer . '/' . substr($file->getPathname(), strlen($directory) + 1);
                }
            }
        }

        Assert::same([], $offenders, 'Application/Domain/Persistence must not reference CentralVet\\Presentation\\');
    }

    public function testSupportParserMatchesPresentationParser(): void
    {
        Assert::true(class_exists(self::SUPPORT_PARSER), self::SUPPORT_PARSER . ' must exist');

        foreach ([self::SUPPORT_PARSER, self::PRESENTATION_PARSER] as $class) {
            Assert::same('2026-10-01 11:00', $class::parse('01/10/2026 11:00')->format('Y-m-d H:i'), "{$class}::parse d/m/Y");
            Assert::throws(InvalidArgumentException::class, static fn () => $class::parse('31/02/2026 11:00'), "{$class}::parse('31/02/2026 11:00') must throw");
        }
    }
}
