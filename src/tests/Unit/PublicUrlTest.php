<?php
declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;

final class PublicUrlTest
{
    public function testToJoinsBaseAndPathWithSingleSlash(): void
    {
        $url = new PublicUrl('https://app.centralvet.com.br/');
        Assert::same('https://app.centralvet.com.br/index.php?class=X', $url->to('index.php?class=X'));
        Assert::same('https://app.centralvet.com.br/index.php?class=X', $url->to('/index.php?class=X'));
    }

    public function testRejectsEmptyAndNonHttpUrls(): void
    {
        Assert::throws(\RuntimeException::class, static fn () => new PublicUrl(''));
        Assert::throws(\RuntimeException::class, static fn () => new PublicUrl('javascript:x'));
        Assert::throws(\RuntimeException::class, static fn () => new PublicUrl('ftp://host.example'));
        Assert::throws(\RuntimeException::class, static fn () => new PublicUrl('https://'));
    }

    public function testFromEnvironmentReadsAppPublicUrl(): void
    {
        $previous = getenv('APP_PUBLIC_URL');
        try {
            putenv('APP_PUBLIC_URL=http://localhost:8080');
            Assert::same('http://localhost:8080/index.php', PublicUrl::fromEnvironment()->to('index.php'));
            putenv('APP_PUBLIC_URL');
            Assert::throws(\RuntimeException::class, static fn () => PublicUrl::fromEnvironment());
        } finally {
            putenv($previous === false ? 'APP_PUBLIC_URL' : 'APP_PUBLIC_URL=' . $previous);
        }
    }
}
