<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-06: a cortina de cancelamento de uma venda já cancelada mostra
 * o selo "Cancelled" e não oferece o botão de confirmar o cancelamento.
 * Lê o código-fonte: a tela depende do Adianti e do banco, que a suíte
 * unitária não sobe.
 */
final class SaleCancelFormCancelledStateTest
{
    public function testConstructorDoesNotAddTheCancelAction(): void
    {
        $constructor = $this->method('__construct');

        Assert::true($constructor !== '', 'construtor não encontrado');
        Assert::false(str_contains($constructor, "'onCancel'"), 'o construtor não pode adicionar a ação de cancelar');
    }

    public function testOnEditAddsTheCancelActionOnlyForSalesNotCancelled(): void
    {
        $onEdit = $this->method('onEdit');

        Assert::true(
            preg_match('/STATUS_CANCELLED\)\s*\{((?:(?!addAction).)*?)\}\s*else\s*\{[^{}]*addAction\(\s*_t\(\'Cancel sale\'\)\s*,\s*new TAction\(\[__CLASS__, \'onCancel\'\]\)/s', $onEdit, $match) === 1,
            'onEdit deve chamar addAction do cancelamento só no ramo else da venda cancelada',
        );
        Assert::stringContains("CvBadge::create(_t('Cancelled')", $match[1] ?? '');
        Assert::same(1, substr_count($onEdit, 'addAction('), 'uma só chamada de addAction em onEdit');
    }

    private function method(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/app/control/clinic/SaleCancelForm.php';
        $source = is_file($path) ? (string) file_get_contents($path) : '';

        if (preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}\n/s', $source, $match) !== 1) {
            return '';
        }

        return $match[0];
    }
}
