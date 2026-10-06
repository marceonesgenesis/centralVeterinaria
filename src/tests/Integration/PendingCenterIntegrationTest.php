<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7A, T-19: a Central de Pendências (`PendingCenter`) renderiza uma
 * lista de PendingItem injetada (FakePendingItemQuery + política que
 * permite) sem tocar no banco. Roda num processo PHP separado porque os
 * controllers Adianti só carregam com `init.php` (padrão de
 * BedFormIntegrationTest / HospitalizationBoardIntegrationTest).
 *
 * Confere: nome de paciente com `<script>` escapado; `href` do "Resolver"
 * igual ao `deepLinkUrl()` de cada item (escapado no atributo) com
 * `cv-touch-target`; estado vazio; `type` desconhecido lista todos;
 * `type` conhecido filtra.
 */
final class PendingCenterIntegrationTest
{
    private const SCRIPT = <<<'PHP'
        $scenario = json_decode($argv[1], true);
        $now = new DateTimeImmutable('2031-03-10 10:00:00');
        $items = [];
        if ($scenario['items']) {
            $items[] = new CentralVet\Domain\PendingItem('exam_result', 11, '<script>x</script>', 'Hemograma', new DateTimeImmutable('2031-03-09 08:00:00'), 7, 'ExamResultForm', ['exam_request_id' => 11, 'encounter_id' => 5]);
            $items[] = new CentralVet\Domain\PendingItem('vaccine_due', 22, 'Rex', 'V10', new DateTimeImmutable('2031-03-20 00:00:00'), null, 'VaccinationCardView', ['patient_id' => 9]);
            $items[] = new CentralVet\Domain\PendingItem('hospitalization_administration', 33, 'Mia', 'Dipirona', new DateTimeImmutable('2031-03-10 10:30:00'), 8, 'HospitalizationView', ['id' => 4, 'tab' => 'administrations']);
        }
        $context = CentralVet\Tenancy\TenantContext::authenticated(1, 7, 3);
        $service = new CentralVet\Application\PendingCenterService(
            new CentralVet\Tests\Support\FakePendingItemQuery($items),
            new CentralVet\Tests\Support\FakeAuthorizationPolicy(true),
            $context,
            static fn (): DateTimeImmutable => $now,
        );
        $out = ['urls' => array_map(static fn ($i) => $i->deepLinkUrl(), $items)];
        ob_start();
        try {
            PendingCenter::buildContent($service, $scenario['param'], static fn (array $ids): array => [7 => 'Dra. Ana', 8 => 'Enf. Bia'])->show();
        } catch (Throwable $e) {
            $out['error'] = get_class($e) . ': ' . $e->getMessage();
        }
        $out['html'] = (string) ob_get_clean();
        echo json_encode($out);
        PHP;

    /**
     * @param array<string, string> $param
     * @return array{html: string, urls: list<string>, error?: string}
     */
    private function render(bool $withItems, array $param): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";' . self::SCRIPT;
        $scenario = json_encode(['items' => $withItems, 'param' => $param]);

        $out = shell_exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' -- ' . escapeshellarg((string) $scenario) . ' 2>/dev/null'
        );
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'PendingCenter subprocess output: ' . (string) $out);
        Assert::true(!isset($decoded['error']), 'PendingCenter::buildContent threw: ' . (string) ($decoded['error'] ?? ''));

        return $decoded;
    }

    public function testPatientNameIsEscaped(): void
    {
        $html = $this->render(true, [])['html'];

        Assert::stringContains('&lt;script&gt;x&lt;/script&gt;', $html, 'patient name must be HTML-escaped');
        Assert::false(str_contains($html, '<script>x</script>'), 'raw <script> from the patient name must not reach the page');
    }

    public function testEachRowResolvesToItsDeepLinkWithTouchTarget(): void
    {
        $result = $this->render(true, []);
        $html = $result['html'];

        Assert::count(3, $result['urls'], 'fixture builds three items');
        foreach ($result['urls'] as $url) {
            $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            Assert::true(
                (bool) preg_match('/<a\b[^>]*href="' . preg_quote($escaped, '/') . '"[^>]*>/', $html, $match),
                'row must link to ' . $url
            );
            Assert::stringContains('cv-touch-target', $match[0], 'resolve link must be a touch target: ' . $match[0]);
            Assert::stringContains('generator="adianti"', $match[0], 'resolve link must navigate inside Adianti: ' . $match[0]);
        }
        Assert::stringContains('Dra. Ana', $html, 'responsible name must be shown');
        Assert::false(str_contains($html, 'cv-pending-empty'), 'empty state must not show with items');
    }

    public function testEmptyListShowsEmptyState(): void
    {
        $html = $this->render(false, [])['html'];

        Assert::stringContains('cv-pending-empty', $html, 'empty list must render the empty state');
        // pt ("Nenhuma pendência") chega com a T-21; até lá a chave en aparece no texto.
        Assert::true(str_contains($html, 'Nenhuma pendência') || str_contains($html, 'No pending items'), 'empty state text must be shown');
    }

    public function testUnknownTypeListsAllItems(): void
    {
        $result = $this->render(true, ['type' => 'bogus<type>']);
        $html = $result['html'];

        foreach ($result['urls'] as $url) {
            Assert::stringContains(htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html, 'unknown type must list every item: ' . $url);
        }
        Assert::false(str_contains($html, 'bogus<type>'), 'unknown type must not be echoed raw');
    }

    public function testKnownTypeFiltersItems(): void
    {
        $result = $this->render(true, ['type' => 'vaccine_due']);
        $html = $result['html'];

        Assert::stringContains('index.php?class=VaccinationCardView&amp;patient_id=9', $html, 'filtered type must be listed');
        Assert::false(str_contains($html, 'class=ExamResultForm'), 'other types must be filtered out');
    }
}
