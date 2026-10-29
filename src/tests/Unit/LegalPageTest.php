<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Legal\LegalDocuments;
use CentralVet\Legal\LegalPage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5 T-06: termos de uso e política de privacidade (rascunho) servidos
 * por `legal.php`, página pública sem sessão.
 */
final class LegalPageTest
{
    private const TERMS_SECTIONS = [
        'Objeto',
        'Contas e perfis de acesso',
        'Planos, cobrança e cancelamento',
        'Dados pessoais e LGPD',
        'Exportação dos dados',
        'Suporte',
        'Piloto e módulos em breve',
        'Responsabilidades',
        'Foro',
    ];

    private const PRIVACY_SECTIONS = [
        'Dados que tratamos',
        'Finalidades e bases legais',
        'Compartilhamento',
        'Retenção',
        'Direitos do titular',
        'Exportação e exclusão',
        'Cookies',
        'Contato do encarregado',
    ];

    public function testCurrentVersionsListsBothDocuments(): void
    {
        Assert::same(
            ['terms' => 'termos-uso-2026-10', 'privacy' => 'privacidade-2026-10'],
            LegalDocuments::currentVersions(),
        );
    }

    public function testTermsRenderWithDraftMarkerVersionAndSections(): void
    {
        $page = LegalPage::render('termos');

        Assert::same(200, $page['status']);
        Assert::stringContains(LegalDocuments::DRAFT_MARKER, $page['body']);
        Assert::stringContains(LegalDocuments::TERMS_VERSION, $page['body']);
        Assert::stringContains('Termos de Uso', $page['body']);
        Assert::stringContains('pelo app, com efeito no fim do período pago', $page['body']);
        Assert::stringContains('30 dias', $page['body']);
        foreach (self::TERMS_SECTIONS as $section) {
            $heading = $section === 'Planos, cobrança e cancelamento'
                ? '<h2 id="cancelamento">' . $section . '</h2>'
                : '<h2>' . $section . '</h2>';
            Assert::stringContains($heading, $page['body'], 'seção ausente: ' . $section);
        }
    }

    public function testTermsCancellationSectionHasAnchorForSupportLink(): void
    {
        $page = LegalPage::render('termos');

        Assert::stringContains('<h2 id="cancelamento">Planos, cobrança e cancelamento</h2>', $page['body']);
        Assert::stringContains(LegalDocuments::TERMS_VERSION, $page['body']);
    }

    public function testPrivacyRenderWithDraftMarkerVersionAndSections(): void
    {
        $page = LegalPage::render('privacidade');

        Assert::same(200, $page['status']);
        Assert::stringContains(LegalDocuments::DRAFT_MARKER, $page['body']);
        Assert::stringContains(LegalDocuments::PRIVACY_VERSION, $page['body']);
        Assert::stringContains('Política de Privacidade', $page['body']);
        Assert::stringContains('Stripe', $page['body']);
        Assert::stringContains('30 dias', $page['body']);
        foreach (self::PRIVACY_SECTIONS as $section) {
            Assert::stringContains('<h2>' . $section . '</h2>', $page['body'], 'seção ausente: ' . $section);
        }
    }

    public function testUnknownOrTraversalDocumentIsNotFound(): void
    {
        foreach (['../config/application', '', 'xyz', 'termos-de-uso'] as $doc) {
            $page = LegalPage::render($doc);

            Assert::same(404, $page['status'], 'doc: ' . $doc);
            Assert::stringContains('Documento não encontrado', $page['body']);
        }
    }

    public function testEntryPointSendsHeadersWithoutSession(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/legal.php');

        Assert::stringContains('LegalPage::CSP', $source);
        Assert::stringContains('X-Content-Type-Options', $source);
        Assert::false(str_contains($source, 'session_start'), 'legal.php não pode abrir sessão');
        Assert::false(str_contains($source, 'init.php'), 'legal.php não carrega o init.php do app');
    }

    public function testCspIsRestrictive(): void
    {
        Assert::same(
            "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            LegalPage::CSP,
        );
    }
}
