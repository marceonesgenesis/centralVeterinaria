<?php

declare(strict_types=1);

namespace {
    if (!class_exists('CvFormat', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvFormat.php';
    }
    if (!class_exists('CvAvatar', false)) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvAvatar.php';
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

        public function testPlainUtf8NameIsUnchanged(): void
        {
            Assert::same('Pérola Ação', CvAvatar::titleFor('Pérola Ação'));
        }
    }
}
