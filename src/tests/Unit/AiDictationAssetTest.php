<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Fase 8A, T-10: o ditado da anamnese usa a Web Speech API do navegador, que
 * envia o áudio ao serviço de reconhecimento de voz do fabricante do navegador
 * (Correção 1: o envio é declarado num aviso visível junto ao botão), e o
 * painel de IA tem alvos de toque de 44 px com rótulos à esquerda.
 */
final class AiDictationAssetTest
{
    private static function templates(): string
    {
        return dirname(__DIR__, 2) . '/app/templates/adminbs5';
    }

    private static function read(string $relative): string
    {
        $path = self::templates() . '/' . $relative;
        Assert::true(is_file($path), "{$relative} must exist");

        return (string) file_get_contents($path);
    }

    public function testDictationScriptExposesAttachOnWindow(): void
    {
        $js = self::read('js/cv-ai-dictation.js');

        Assert::stringContains('window.CvDictation', $js, 'cv-ai-dictation.js must define window.CvDictation');
        Assert::stringContains('attach', $js, 'window.CvDictation must expose attach');
        Assert::stringContains("'use strict'", $js, "cv-ai-dictation.js must use 'use strict'");
    }

    public function testDictationScriptUsesBothSpeechRecognitionConstructors(): void
    {
        $js = self::read('js/cv-ai-dictation.js');

        Assert::true(preg_match('/(?<!webkit)SpeechRecognition/', $js) === 1, 'cv-ai-dictation.js must cite SpeechRecognition');
        Assert::stringContains('webkitSpeechRecognition', $js, 'cv-ai-dictation.js must cite webkitSpeechRecognition');

        foreach (['data-label-start', 'data-label-stop', 'data-label-denied', 'role', 'not-allowed'] as $needle) {
            Assert::stringContains($needle, $js, "cv-ai-dictation.js must handle {$needle}");
        }
    }

    public function testDictationScriptNeverSendsAudioOrText(): void
    {
        $js = self::read('js/cv-ai-dictation.js');

        foreach (['fetch(', 'XMLHttpRequest', 'MediaRecorder', 'getUserMedia'] as $forbidden) {
            Assert::false(str_contains($js, $forbidden), "cv-ai-dictation.js must not contain {$forbidden}");
        }
    }

    public function testAiPanelClassesExistWithTouchTargetsAndLeftLabels(): void
    {
        $css = self::read('cv-components.css');

        foreach (['.cv-ai-panel', '.cv-ai-origin', '.cv-ai-actions', '.cv-ai-status'] as $class) {
            Assert::true(preg_match('/' . preg_quote($class, '/') . '[\s,{.:]/', $css) === 1, "cv-components.css must define {$class}");
        }

        Assert::true(
            preg_match('/\.cv-ai-actions[^{]*\{[^}]*min-height:\s*var\(--cv-touch-target/', $css) === 1,
            '.cv-ai-actions must use min-height: var(--cv-touch-target'
        );

        preg_match_all('/[^{}]*\.cv-ai-[^{]*\{[^}]*\}/', $css, $blocks);
        Assert::true(count($blocks[0]) > 0, 'cv-ai-* rules must exist');
        foreach ($blocks[0] as $block) {
            Assert::false(
                preg_match('/text-align:\s*(right|center)/', $block) === 1,
                'cv-ai-* rules must not align labels right or center'
            );
        }
    }

    public function testDictationScriptShowsTheVoiceServiceNoticeNextToTheButton(): void
    {
        $js = self::read('js/cv-ai-dictation.js');

        Assert::stringContains('data-label-notice', $js, 'cv-ai-dictation.js must read the notice text from data-label-notice');
        Assert::stringContains('cv-ai-dictation-notice', $js, 'cv-ai-dictation.js must render .cv-ai-dictation-notice');
        Assert::stringContains('aria-describedby', $js, 'the dictation button must be described by the notice');

        $css = self::read('cv-components.css');
        Assert::true(
            preg_match('/\.cv-ai-dictation-notice\s*\{[^}]*text-align:\s*left/', $css) === 1,
            '.cv-ai-dictation-notice must exist and be left aligned'
        );
    }

    public function testDictationScriptAnnouncesListeningAndEveryRecognitionError(): void
    {
        $js = self::read('js/cv-ai-dictation.js');

        foreach (["'no-speech'", "'audio-capture'", "'network'", "'aborted'", "'not-allowed'", "'service-not-allowed'"] as $code) {
            Assert::stringContains($code, $js, "cv-ai-dictation.js must handle the {$code} recognition error");
        }

        foreach (['data-label-listening', 'data-label-no-speech', 'data-label-audio-capture', 'data-label-network', 'data-label-error'] as $attribute) {
            Assert::stringContains($attribute, $js, "cv-ai-dictation.js must read {$attribute}");
        }
    }

    public function testEmptyStatusStaysInTheAccessibilityTree(): void
    {
        $css = self::read('cv-components.css');

        Assert::true(
            preg_match('/\.cv-ai-status:empty\s*\{([^}]*)\}/', $css, $match) === 1,
            '.cv-ai-status:empty must exist'
        );
        Assert::false(
            preg_match('/display:\s*none/', $match[1]) === 1,
            '.cv-ai-status:empty must not use display: none (it is a live region)'
        );
        Assert::stringContains('clip', $match[1], '.cv-ai-status:empty must hide the region visually with clip');
    }

    public function testNoAssetClaimsTheAudioStaysInTheBrowser(): void
    {
        $sources = [
            'js/cv-ai-dictation.js' => self::read('js/cv-ai-dictation.js'),
            'AiDictationAssetTest.php' => (string) file_get_contents(__FILE__),
        ];
        $falseClaims = ['/fica[\s*]+no[\s*]+navegador/iu', '/n[ãa]o[\s*]+envia[\s*]+[áa]udio/iu', '/n[ãa]o[\s*]+sai[\s*]+do[\s*]+navegador/iu'];

        foreach ($sources as $name => $content) {
            foreach ($falseClaims as $pattern) {
                Assert::false(preg_match($pattern, $content) === 1, "{$name} must not claim the audio stays in the browser ({$pattern})");
            }
        }
    }
}
