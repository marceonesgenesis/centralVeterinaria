<?php

declare(strict_types=1);

namespace {
    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
    if (!class_exists('CvAvatar', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvAvatar.php';
    }
    // CvAvatar::placeholder() monta um TElement global; fora do init.php o
    // alias do framework não existe, então o teste o cria a partir da classe
    // real (lib/adianti, só leitura).
    if (!class_exists('TElement')) {
        if (!class_exists('Adianti\\Widget\\Base\\TElement')) {
            require dirname(__DIR__, 2) . '/lib/adianti/widget/base/TElement.php';
        }
        class_alias('Adianti\\Widget\\Base\\TElement', 'TElement');
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Tests\Support\Assert;
    use CvAvatar;

    /**
     * T-64: o [title] do avatar vira tooltip tippy com allowHTML (framework).
     * O valor sai escapado duas vezes: o navegador decodifica o atributo uma
     * vez e o tooltip recebe texto, não marcação.
     */
    final class CvAvatarTitleTest
    {
        public function testMarkupInNameIsEscapedTwice(): void
        {
            $title = CvAvatar::titleFor('<img src=x onerror=alert(1)> R2');

            Assert::true(str_contains($title, '&amp;lt;img'), 'title must carry &amp;lt;img');
            Assert::false(str_contains($title, '<img'), 'title must not carry a raw tag');
            Assert::false(
                (bool) preg_match('/(?<!&amp;)&lt;img src/', $title),
                'title must not carry &lt;img src without the &amp; prefix'
            );
        }

        public function testAmpersandIsEscapedTwice(): void
        {
            Assert::true(str_contains(CvAvatar::titleFor('Rex & Cia'), '&amp;amp;'), 'ampersand must be escaped twice');
        }

        /**
         * T-09: o [title] que placeholder() grava precisa ser o de titleFor().
         * Reverter CvAvatar.php:16 para CvFormat::e($name) escapa uma vez só
         * e reprova este teste (os de titleFor, acima, continuam passando).
         */
        public function testPlaceholderTitleGoesThroughTitleFor(): void
        {
            $name = '<img src=x onerror=alert(1)> R2 & Cia';
            $avatar = CvAvatar::placeholder($name);

            Assert::same(CvAvatar::titleFor($name), $avatar->getProperty('title'));
            Assert::true(
                str_contains((string) $avatar->getProperty('title'), '&amp;lt;img'),
                'placeholder title must carry &amp;lt;img'
            );
        }

        public function testPlainUtf8NameIsUnchanged(): void
        {
            Assert::same('Pérola Ação', CvAvatar::titleFor('Pérola Ação'));
        }
    }
}
