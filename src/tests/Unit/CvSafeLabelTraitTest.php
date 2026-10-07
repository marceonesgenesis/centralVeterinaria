<?php

declare(strict_types=1);

namespace {
    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
    if (!trait_exists('CvSafeLabelTrait', false) && is_file(dirname(__DIR__, 2) . '/app/lib/widget/CvSafeLabelTrait.php')) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvSafeLabelTrait.php';
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Tests\Support\Assert;

    /**
     * T-65: o select2 do framework (TDBUniqueSearch, TCombo com enableSearch)
     * renderiza como HTML o rótulo que contém tag. O atributo virtual
     * <attr>_safe entrega o texto já escapado, e a máscara de busca o envolve
     * em <span> para que o select2 mostre o texto literal, sem &amp; visível.
     */
    final class CvSafeLabelTraitTest
    {
        private function subject(): object
        {
            if (!trait_exists('CvSafeLabelTrait')) {
                throw new \RuntimeException('trait CvSafeLabelTrait does not exist');
            }

            return new class {
                use \CvSafeLabelTrait;

                public $name;

                public function label(): string
                {
                    return $this->safeLabel('name');
                }
            };
        }

        private function labelOf(?string $name): string
        {
            $subject = $this->subject();
            $subject->name = $name;

            return $subject->label();
        }

        public function testMarkupIsEscaped(): void
        {
            $label = $this->labelOf('<img src=x onerror=alert(1)> R2');

            Assert::true(str_contains($label, '&lt;img src=x onerror=alert(1)&gt; R2'), 'label must carry &lt;img ... &gt; R2');
            Assert::false(str_contains($label, '<img'), 'label must not carry a raw tag');
        }

        public function testAmpersandIsEscapedAndAccentKept(): void
        {
            Assert::same('João &amp; Cia', $this->labelOf('João & Cia'), 'ampersand escaped once, accent kept');
        }

        public function testNullAttributeGivesEmptyString(): void
        {
            Assert::same('', $this->labelOf(null), 'null attribute must give an empty label');
        }

        public function testSearchMaskWrapsTheSafeAttributeInSpan(): void
        {
            if (!trait_exists('CvSafeLabelTrait')) {
                throw new \RuntimeException('trait CvSafeLabelTrait does not exist');
            }
            $class = get_class($this->subject());

            Assert::same('<span>{name_safe}</span>', $class::safeSearchMask('name_safe'), 'search mask wraps the attribute');
        }
    }
}
