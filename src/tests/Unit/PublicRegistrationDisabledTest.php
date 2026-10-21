<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * S1 (Etapa 1): o cadastro público legado (SystemRegistrationForm) fica
 * desligado; o botão "Criar conta" do login leva à landing sem depender
 * de user_register.
 */
final class PublicRegistrationDisabledTest
{
    private const TEST_SEED = 'public-registration-test-seed-0123456789abcdef';

    private string|false $previousSeed = false;

    public function setUp(): void
    {
        $this->previousSeed = getenv('APP_SEED');
        putenv('APP_SEED=' . self::TEST_SEED);
    }

    public function tearDown(): void
    {
        if ($this->previousSeed === false) {
            putenv('APP_SEED');
        } else {
            putenv('APP_SEED=' . $this->previousSeed);
        }
    }

    public function testRegistrationFormIsNotPublicClass(): void
    {
        $config = $this->config();

        Assert::false(
            in_array('SystemRegistrationForm', $config['permission']['public_classes'], true),
            'SystemRegistrationForm must not be in permission.public_classes'
        );
    }

    public function testUserRegisterIsDisabled(): void
    {
        Assert::same('0', $this->config()['permission']['user_register']);
    }

    public function testPublicMenusDoNotLinkRegistrationForm(): void
    {
        foreach (['menu-public.xml', 'menu-bottom-public.xml'] as $menu) {
            Assert::false(
                str_contains($this->read($menu), 'SystemRegistrationForm'),
                "{$menu} must not reference SystemRegistrationForm"
            );
        }
    }

    public function testLoginCreateAccountGoesToLandingWithoutUserRegister(): void
    {
        $source = $this->read('app/control/admin/LoginForm.php');

        Assert::stringContains('landing.php#cart', $source);
        Assert::false(str_contains($source, 'user_register'), 'LoginForm must not depend on user_register');
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = require dirname(__DIR__, 2) . '/app/config/application.php';
        Assert::true(is_array($config), 'application.php must return an array');

        return $config;
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        Assert::true(is_file($path), "{$relative} must exist");

        return (string) file_get_contents($path);
    }
}
