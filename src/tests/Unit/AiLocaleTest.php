<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiLocale;
use CentralVet\Tests\Support\Assert;

final class AiLocaleTest
{
    public function testPortugueseCodeMapsToPt(): void
    {
        Assert::same('pt', AiLocale::fromLanguage('pt'));
    }

    public function testBrazilianPortugueseMapsToPt(): void
    {
        Assert::same('pt', AiLocale::fromLanguage('pt_BR'));
    }

    public function testEnglishCodeMapsToEn(): void
    {
        Assert::same('en', AiLocale::fromLanguage('en'));
    }

    public function testEnglishIsCaseInsensitive(): void
    {
        Assert::same('en', AiLocale::fromLanguage('EN_us'));
    }

    public function testOtherLanguageFallsBackToPt(): void
    {
        Assert::same('pt', AiLocale::fromLanguage('es'));
    }

    public function testEmptyStringFallsBackToPt(): void
    {
        Assert::same('pt', AiLocale::fromLanguage(''));
    }

    public function testNullFallsBackToPt(): void
    {
        Assert::same('pt', AiLocale::fromLanguage(null));
    }

    public function testEveryAiLocaleRuleDelegatesToAiLocale(): void
    {
        $app = dirname(__DIR__, 2) . '/app';
        $files = [
            $app . '/control/clinic/AiSettingsForm.php',
            $app . '/Core/Ai/AiPromptCatalog.php',
            $app . '/control/clinic/ExamResultForm.php',
            $app . '/control/clinic/EncounterView.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);
            Assert::false(
                (bool) preg_match("/str_starts_with\\([^;]*'(pt|en)'/", $source),
                $name . ' must not have its own pt/en locale rule'
            );
            Assert::stringContains('AiLocale::fromLanguage(', $source, $name . ' must delegate to AiLocale::fromLanguage');
        }
    }
}
