<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Etapa 2, T-05 (P1): the PHP image must keep cookies out of $_REQUEST
 * (request_order = "GP"); otherwise the Adianti URL builders copy
 * PHPSESSID from $_REQUEST into paginated list links.
 */
final class PhpRequestOrderTest
{
    public function testAppIniSetsRequestOrderToGetAndPostOnly(): void
    {
        $path = dirname(__DIR__, 3) . '/docker/php/conf.d/app.ini';
        Assert::true(is_file($path), 'docker/php/conf.d/app.ini must be mounted');

        $settings = parse_ini_file($path);
        Assert::true(is_array($settings), 'app.ini must parse');
        Assert::true(array_key_exists('request_order', $settings), 'app.ini must define request_order');
        Assert::same('GP', $settings['request_order']);
    }
}
