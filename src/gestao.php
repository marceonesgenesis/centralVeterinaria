<?php

declare(strict_types=1);

/**
 * Ponto de entrada exclusivo da Área de Gestão do Sistema (Back-office) — Central Vet Pro
 *
 * Rota dedicada para operadores e equipe interna da plataforma:
 * - Login separado (GestaoLoginForm)
 * - Restrição rigorosa: usuários de clínica (tenants) não acessam
 * - Sem seletor de unidade clínica
 * - 2FA obrigatório
 */

chdir(__DIR__);
require_once __DIR__ . '/config/health.php';

if (centralvet_handle_health_request()) {
    exit;
}

require_once __DIR__ . '/init.php';

$ini = AdiantiApplicationConfig::get();
$theme = $ini['general']['theme'] ?? 'adminbs5';

new TSession(\CentralVet\Session\SessionHandlerFactory::createFromEnvironment());
ApplicationAuthenticationService::checkMultiSession();
ApplicationTranslator::setLanguage(TSession::getValue('user_language') ?: 'pt', true);

// Se logado e for membro da equipe de gestão (back-office)
if (TSession::getValue('logged') && TSession::getValue('cv_backoffice')) {
    if (isset($_REQUEST['template']) && $_REQUEST['template'] === 'iframe') {
        $content = file_get_contents("app/templates/{$theme}/iframe.html");
    } else {
        $content = file_get_contents("app/templates/{$theme}/layout.html");
        
        // Menu de navegação específico da equipe de gestão do sistema
        $menuXml = file_exists('menu-gestao.xml') ? 'menu-gestao.xml' : 'menu.xml';
        $content = str_replace('{MENU}', AdiantiMenuBuilder::parse($menuXml, $theme), $content);
        $content = str_replace('{MENUTOP}', AdiantiMenuBuilder::parseNavBar('menu-top.xml', $theme), $content);
        $content = str_replace('{MENUBOTTOM}', AdiantiMenuBuilder::parseNavBar('menu-bottom.xml', $theme), $content);
    }
} else {
    // Se não estiver logado ou se for usuário logado sem privilégios de back-office, direciona para o GestaoLoginForm
    $_REQUEST['class'] = 'GestaoLoginForm';
    $content = file_get_contents("app/templates/{$theme}/login.html");
}

$content = ApplicationTranslator::translateTemplate($content);
$content = AdiantiTemplateParser::parse($content);

echo $content;

