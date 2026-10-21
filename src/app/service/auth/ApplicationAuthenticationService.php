<?php
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use CentralVet\Billing\AccessDecision;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Security\CsrfToken;
use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Security\SearchParameterGuard;
use CentralVet\Tenancy\AdiantiSessionContextSource;
use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tenancy\TenantUserActivation;

class ApplicationAuthenticationService
{
    /** Decisões de SubscriptionAccessGuard em cache na sessão, por tenant e controller (T-19). */
    public const SUBSCRIPTION_ACCESS_SESSION_KEY = 'cv_subscription_access';
    
    /** Validade, em segundos, de cada decisão em cache. */
    public const SUBSCRIPTION_ACCESS_TTL = 60;
    
    /**
     * Authenticate user and load permissions
     */
    public static function authenticate($login, $password, $load_session_vars = true)
    {
        $ini  = AdiantiApplicationConfig::get();
        
        TTransaction::open('permission');
        $user = SystemUser::validate( $login );
        
        // call loaders to made available this attrs outside transactions
        $user->get_unit();
        $user->get_frontpage();
        
        if ($user)
        {
            if (!empty($ini['permission']['auth_service']) && class_exists($ini['permission']['auth_service']))
            {
                $service = $ini['permission']['auth_service'];
                $service::authenticate( $login, $password );
            }
            else
            {
                SystemUser::authenticate( $login, $password );
            }

            if ($load_session_vars)
            {
                self::loadSessionVars($user);
            }
            
            TTransaction::close();
            
            return $user;
        }
        
        TTransaction::close();
    }
    
    /**
     * Set Unit when multi unit is turned on
     * @param $unit_id Unit id
     */
    public static function setUnit($unit_id)
    {
        $ini  = AdiantiApplicationConfig::get();
        
        if (!empty($ini['general']['multiunit']) && $ini['general']['multiunit'] == '1' && !empty($unit_id))
        {
            TTransaction::openFake('permission');
            $is_valid = in_array($unit_id, SystemUser::newFromLogin( TSession::getValue('login') )->getSystemUserUnitIds());
            TTransaction::close();
            
            if (!$is_valid)
            {
                throw new Exception(_t('Unauthorized access to that unit'));
            }
            
            TSession::setValue('userunitid',   $unit_id );
            TSession::setValue('userunitname', SystemUnit::findInTransaction('permission', $unit_id)->name);
            TSession::setValue('userunitcustomcode', SystemUnit::findInTransaction('permission', $unit_id)->custom_code);
            
            if (!empty($ini['general']['multi_database']) and $ini['general']['multi_database'] == '1')
            {
                TSession::setValue('unit_database', SystemUnit::findInTransaction('permission', $unit_id)->connection_name );
            }
        }
    }
    
    /**
     * Set language when multi language is turned on
     * @param $lang_id Language id
     */
    public static function setLang($lang_id)
    {
        $ini  = AdiantiApplicationConfig::get();
        
        if (!empty($ini['general']['multi_lang']) and $ini['general']['multi_lang'] == '1' and !empty($lang_id))
        {
            TSession::setValue('user_language', $lang_id );
        }
    }
    
    /**
     * Load user session variables
     */
    public static function loadSessionVars($user, $open_transaction = false)
    {
        if ($open_transaction)
        {
            TTransaction::open('permission');
        }
        
        $programs = $user->getPrograms();
        $programs['LoginForm'] = TRUE;
        
        // T-15: a login without a previous logout must not inherit the previous user's pending uploads
        TSession::delValue(\CvUpload::SESSION_KEY);
        
        TSession::setValue('need_renewal_password', false);
        TSession::setValue('logged', TRUE);
        TSession::setValue('login', $user->login);
        TSession::setValue('userid', $user->id);
        TSession::setValue('tenantid', self::resolveTenantId($user->id));
        TSession::setValue('usergroupids', $user->getSystemUserGroupIds());
        TSession::setValue('userunitids', $user->getSystemUserUnitIds());
        TSession::setValue('userroleids', $user->getSystemUserRoleIds());
        TSession::setValue('userroles', $user->getSystemUserRoleCodes());
        TSession::setValue('username', $user->name);
        TSession::setValue('usermail', $user->email);
        TSession::setValue('usercustomcode', $user->custom_code);
        TSession::setValue('frontpage', '');
        TSession::setValue('programs',$programs);
        TSession::setValue('methods', $user->getMethods());
        
        if (!empty($user->unit))
        {
            TSession::setValue('userunitid',$user->unit->id);
            TSession::setValue('userunitname', $user->unit->name);
            TSession::setValue('userunitcustomcode', $user->unit->custom_code);
        }
        
        if ($open_transaction)
        {
            TTransaction::close();
        }
        
        if (TAPCache::enabled())
        {
            TAPCache::setValue('session_'.TSession::getValue('login'), session_id());
        }

        if (\CentralVet\Session\SessionHandlerFactory::isRedisEnabled())
        {
            try
            {
                \CentralVet\Session\SessionRegistry::fromEnvironment()->register((int) $user->id, (string) session_id());
            }
            catch (\Throwable $e)
            {
                error_log('[session-registry] register failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Resolve the tenant a system user belongs to, for CentralVet\Tenancy\TenantContext.
     * Requires an open 'permission' transaction (loadSessionVars() always has one).
     */
    private static function resolveTenantId(int $userId): ?int
    {
        $connection = TTransaction::get();
        $statement = $connection->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = ? LIMIT 1');
        $statement->execute([$userId]);
        $tenantId = $statement->fetchColumn();

        return $tenantId !== false ? (int) $tenantId : null;
    }

    /**
     * Execute onAfterLogin hook
     */
    public static function onAfterLogin()
    {
        $ini  = AdiantiApplicationConfig::get();
        
        // T-19: o primeiro login do convidado ativa o vínculo com a clínica.
        // Falha aqui não impede o login; o próximo login tenta de novo.
        try
        {
            TTransaction::open('permission');
            TenantUserActivation::activateInvited(TTransaction::get(), (int) TSession::getValue('userid'));
            TTransaction::close();
        }
        catch (\Throwable $e)
        {
            TTransaction::rollbackAll();
            error_log('[tenant-user] activation failed: ' . $e->getMessage());
        }
        
        if (!empty($ini['hooks']['after_login']) && is_array($ini['hooks']['after_login']))
        {
            foreach ($ini['hooks']['after_login'] as $after_login_hook)
            {
                if (is_callable($after_login_hook))
                {
                    $after_login_hook([]);
                }
            }
        }
    }
    
    /**
     * Execute onBeforeLogout hook
     */
    public static function onBeforeLogout()
    {
        $ini  = AdiantiApplicationConfig::get();
        
        if (!empty($ini['hooks']['before_logout']) && is_array($ini['hooks']['before_logout']))
        {
            foreach ($ini['hooks']['before_logout'] as $before_logout_hook)
            {
                if (is_callable($before_logout_hook))
                {
                    $before_logout_hook([]);
                }
            }
        }
    }
    
    /**
     * Authenticate user from JWT token
     */
    public static function fromToken($token)
    {
        $ini = AdiantiApplicationConfig::get();
        $key = APPLICATION_NAME . $ini['general']['seed'];
        
        if (empty($ini['general']['seed']))
        {
            throw new Exception('Application seed not defined');
        }
        
        $token = (array) JWT::decode($token, new Key($key, 'HS256'));
        
        $login   = $token['user'];
        $userid  = $token['userid'];
        $name    = $token['username'];
        $email   = $token['usermail'];
        $expires = $token['expires'];
        
        if ($expires < strtotime('now'))
        {
            throw new Exception('Token expired. This operation is not allowed');
        }
        
        TSession::setValue('logged',   TRUE);
        TSession::setValue('login',    $login);
        TSession::setValue('userid',   $userid);
        TSession::setValue('username', $name);
        TSession::setValue('usermail', $email);
    }
    
    /**
     * Guard central de mutação (S2) e das buscas do Adianti (S7), antes do
     * despacho de engine.php/index.php: garante o token CSRF da sessão,
     * espelha-o no cookie cv_csrf (lido pelo cv-shell.js para o cabeçalho
     * X-CSRF-Token) e aplica a decisão da MutationRequestPolicy.
     */
    public static function enforceMutationGuard(): void
    {
        $token = TSession::getValue(MutationRequestPolicy::SESSION_KEY);
        if (!is_string($token) || $token === '')
        {
            $token = CsrfToken::generate();
            TSession::setValue(MutationRequestPolicy::SESSION_KEY, $token);
        }
        
        if (($_COOKIE[MutationRequestPolicy::COOKIE_NAME] ?? null) !== $token && !headers_sent())
        {
            setcookie(MutationRequestPolicy::COOKIE_NAME, $token, [
                'path'     => '/',
                'secure'   => self::isHttps(),
                'httponly' => false,
                'samesite' => 'Strict',
            ]);
        }
        
        // A imagem PHP fixa request_order = "GP" (P1), e $_REQUEST não traz
        // cookies. Em hospedagem sem essa configuração, request_order vazio faz
        // $_REQUEST seguir variables_order (EGPCS) e incluir os cookies: o
        // Adianti repassa $_REQUEST como $param e copiaria cv_csrf para as URLs
        // que monta. withoutCsrfCookie fica como defesa; o token só trafega no
        // cabeçalho.
        $_REQUEST = self::withoutCsrfCookie($_REQUEST);
        
        $class = (string) ($_REQUEST['class'] ?? '');
        
        try
        {
            $tenantId = TenantContext::fromAuthenticatedSession(new AdiantiSessionContextSource())->tenantId();
        }
        catch (MissingTenantContext $e)
        {
            $tenantId = null;
        }
        
        $searchDenial = SearchParameterGuard::check($class, $_REQUEST, $tenantId);
        if ($searchDenial !== null)
        {
            self::denyRequest('[search-guard] denied ' . $class . ': ' . $searchDenial);
        }
        
        $decision = MutationRequestPolicy::decideFromRequest($_SERVER, $_REQUEST, $token);
        
        if ($decision === MutationRequestPolicy::STRIP_METHOD)
        {
            unset($_REQUEST['method'], $_GET['method'], $_POST['method']);
        }
        else if ($decision === MutationRequestPolicy::DENY)
        {
            self::denyRequest('[csrf] denied ' . $class . '::' . (string) ($_REQUEST['method'] ?? ''));
        }
    }
    
    /**
     * Parâmetros da requisição sem o cookie do token CSRF.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function withoutCsrfCookie(array $request): array
    {
        unset($request[MutationRequestPolicy::COOKIE_NAME]);
        
        return $request;
    }
    
    /**
     * Bloqueio por assinatura e plano (T-19), logo depois do guard de
     * mutação e só com sessão logada: decide pelo SubscriptionAccessGuard
     * (T-15), guarda a decisão na sessão por 60 s por tenant e controller e,
     * se bloqueado, responde 403 com a mensagem da decisão e o link para a
     * tela da assinatura, sem executar o controller. Sessão sem tenant ou sem
     * controller segue sem bloqueio; tenant sem assinatura é legacy.
     */
    public static function enforceSubscriptionAccess(): void
    {
        $class = (string) ($_REQUEST['class'] ?? '');
        if ($class === '')
        {
            return;
        }
        
        try
        {
            $tenantId = TenantContext::fromAuthenticatedSession(new AdiantiSessionContextSource())->tenantId();
        }
        catch (MissingTenantContext $e)
        {
            return;
        }
        
        $now = time();
        $cache = TSession::getValue(self::SUBSCRIPTION_ACCESS_SESSION_KEY);
        $cache = is_array($cache) ? $cache : [];
        $entry = self::cachedSubscriptionAccess($cache, $tenantId, $class, $now);
        
        if ($entry === null)
        {
            try
            {
                $guard = new SubscriptionAccessGuard(new SubscriptionRepository(PdoConnectionFactory::fromEnvironment()));
                $decision = $guard->decide($tenantId, $class, new DateTimeImmutable());
            }
            catch (\Throwable $e)
            {
                // Sem banco a aplicação inteira já falha; não guarda decisão.
                error_log('[subscription-guard] decision failed for tenant ' . $tenantId . ': ' . $e->getMessage());
                return;
            }
            
            $cache = self::rememberSubscriptionAccess($cache, $tenantId, $class, $decision, $now);
            TSession::setValue(self::SUBSCRIPTION_ACCESS_SESSION_KEY, $cache);
            $entry = $cache[self::subscriptionAccessCacheKey($tenantId, $class)];
        }
        
        if (!$entry['allowed'])
        {
            self::denyRequest(
                '[subscription-guard] denied ' . $class . ' for tenant ' . $tenantId . ': ' . $entry['reason'],
                (string) $entry['message_key'],
                'index.php?class=SubscriptionStatusView'
            );
        }
    }
    
    /** Chave da decisão em cache: tenant e controller. */
    public static function subscriptionAccessCacheKey(int $tenantId, string $controller): string
    {
        return $tenantId . '|' . $controller;
    }
    
    /**
     * Decisão em cache ainda válida para o tenant e o controller, ou null.
     *
     * @param array<string, mixed> $cache
     * @return array{allowed: bool, reason: string, message_key: ?string, expires_at: int}|null
     */
    public static function cachedSubscriptionAccess(array $cache, int $tenantId, string $controller, int $now): ?array
    {
        $entry = $cache[self::subscriptionAccessCacheKey($tenantId, $controller)] ?? null;
        
        if (!is_array($entry) || !isset($entry['expires_at'], $entry['allowed']) || (int) $entry['expires_at'] <= $now)
        {
            return null;
        }
        
        return $entry;
    }
    
    /**
     * Cache com a decisão acrescentada (válida por SUBSCRIPTION_ACCESS_TTL)
     * e sem as entradas vencidas.
     *
     * @param array<string, mixed> $cache
     * @return array<string, array{allowed: bool, reason: string, message_key: ?string, expires_at: int}>
     */
    public static function rememberSubscriptionAccess(array $cache, int $tenantId, string $controller, AccessDecision $decision, int $now): array
    {
        $cache = array_filter(
            $cache,
            static fn ($entry): bool => is_array($entry) && (int) ($entry['expires_at'] ?? 0) > $now
        );
        
        $cache[self::subscriptionAccessCacheKey($tenantId, $controller)] = [
            'allowed'     => $decision->allowed(),
            'reason'      => $decision->reason(),
            'message_key' => $decision->messageKey(),
            'expires_at'  => $now + self::SUBSCRIPTION_ACCESS_TTL,
        ];
        
        return $cache;
    }
    
    /**
     * Responde 403 com a mensagem de T-01 (ou a chave informada, traduzida
     * depois de fixar o idioma da sessão), o link opcional e encerra.
     */
    private static function denyRequest(string $logLine, ?string $messageKey = null, ?string $link = null): void
    {
        ApplicationTranslator::setLanguage(TSession::getValue('user_language'), true);
        http_response_code(403);
        error_log($logLine);
        
        $message = $messageKey !== null
            ? _t($messageKey)
            : _t('The request could not be verified. Reload the page and try again.');
        if ($link !== null)
        {
            $message .= '<br><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" generator="adianti">' . _t('Subscription') . '</a>';
        }
        
        new TMessage('error', $message);
        exit;
    }
    
    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off')
        {
            return true;
        }
        
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    
    /**
     * Check multi session
     */
    public static function checkMultiSession()
    {
        $ini = AdiantiApplicationConfig::get();
        
        if (!TSession::getValue('logged'))
        {
            return;
        }
        
        self::enforceMutationGuard();
        self::enforceSubscriptionAccess();
        
        if (!isset($ini['general']['concurrent_sessions']))
        {
            return;
        }
        
        if ($ini['general']['concurrent_sessions'] == '1')
        {
            return;
        }

        if (\CentralVet\Session\SessionHandlerFactory::isRedisEnabled())
        {
            try
            {
                $registry = \CentralVet\Session\SessionRegistry::fromEnvironment();
                $current_session = $registry->currentSessionId((int) TSession::getValue('userid'));
            }
            catch (\Throwable $e)
            {
                error_log('[session-registry] lookup failed: ' . $e->getMessage());
                return;
            }
        }
        else if (TAPCache::enabled())
        {
            $current_session = TAPCache::getValue('session_'.TSession::getValue('login'));
        }
        else
        {
            new TMessage('error', AdiantiCoreTranslator::translate('PHP Module not found'). ': APCU');
            return;
        }

        if ($current_session)
        {
            if ($current_session !== session_id())
            {
                SystemAccessLogService::registerLogout();
                TSession::freeSession();
                
                $class = 'SystemConcurrentAccessView';
                AdiantiCoreApplication::gotoPage($class, 'onLoad');
                return;
            }
        }
    }
}
