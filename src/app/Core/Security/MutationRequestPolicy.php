<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * Pure rule that decides whether a logged-in request may run Class::method.
 *
 * Allowlist of reads: whatever is not a known read (READ_METHODS,
 * READ_PREFIXES minus MUTATING_OVERRIDES) must arrive by POST with the
 * session CSRF token in the X-CSRF-Token header (value mirrored in the
 * cv_csrf cookie so the front end can read it). Through
 * index.php (top-level navigation, no header possible) a non-read method is
 * stripped so the page only renders. Every list entry is justified in
 * docs/security/csrf-inventario.md.
 */
final class MutationRequestPolicy
{
    public const SESSION_KEY = 'cv_shell_csrf';
    public const COOKIE_NAME = 'cv_csrf';
    public const HEADER_NAME = 'X-CSRF-Token';
    public const SERVER_HEADER_KEY = 'HTTP_X_CSRF_TOKEN';

    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const STRIP_METHOD = 'strip_method';

    /** Exact method names that only read (any class). */
    public const READ_METHODS = [
        'show',
        'onContext',
        'onPhoto',
        'onDownload',
        'downloadFile',
        'onReport',
    ];

    /** Method name prefixes that only read. `onClose` is deliberately absent. */
    public const READ_PREFIXES = [
        'onReload',
        'onEdit',
        'onLoad',
        'onSearch',
        'onView',
        'onShow',
        'onOpen',
        'onClear',
        'onChangeLimit',
        'onExport',
        'onGenerate',
        'onPrint',
        'onDisplay',
    ];

    /** Class::method that look like reads but write. */
    public const MUTATING_OVERRIDES = [
        'AiCopilotPanel::onAsk',
        'CashSessionForm::onOpen',
        'SystemMessageFormView::onView',
        'SaleForm::onClearCart',
    ];

    /** Classes accepted without token (upload to tmp/ only, raw XHR). */
    public const EXEMPT_CLASSES = [
        'AdiantiUploaderService',
        'SystemDocumentUploaderService',
    ];

    /** Class::method accepted without token. */
    public const EXEMPT_ACTIONS = [
        'LoginForm::onLogout',
        'LoginForm::reloadPermissions',
    ];

    public static function isReadOnly(string $class, string $method): bool
    {
        if ($method === '') {
            return true;
        }

        if (in_array($class . '::' . $method, self::MUTATING_OVERRIDES, true)) {
            return false;
        }

        if (in_array($method, self::READ_METHODS, true)) {
            return true;
        }

        foreach (self::READ_PREFIXES as $prefix) {
            if (str_starts_with($method, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function decide(
        string $entryScript,
        string $httpMethod,
        string $class,
        string $method,
        mixed $sentToken,
        mixed $sessionToken
    ): string {
        if (self::isReadOnly($class, $method)
            || in_array($class, self::EXEMPT_CLASSES, true)
            || in_array($class . '::' . $method, self::EXEMPT_ACTIONS, true)
        ) {
            return self::ALLOW;
        }

        if ($entryScript === 'index.php') {
            return self::STRIP_METHOD;
        }

        return CsrfToken::isValid($httpMethod, $sentToken, $sessionToken) ? self::ALLOW : self::DENY;
    }

    /**
     * @param array<string, mixed> $server  $_SERVER
     * @param array<string, mixed> $request $_REQUEST
     */
    public static function decideFromRequest(array $server, array $request, mixed $sessionToken): string
    {
        $script = $server['SCRIPT_NAME'] ?? '';
        $httpMethod = $server['REQUEST_METHOD'] ?? 'GET';
        $class = $request['class'] ?? '';
        $method = $request['method'] ?? '';

        return self::decide(
            basename(is_string($script) ? $script : ''),
            is_string($httpMethod) ? $httpMethod : 'GET',
            is_string($class) ? $class : '',
            is_string($method) ? $method : '',
            $server[self::SERVER_HEADER_KEY] ?? null,
            $sessionToken
        );
    }
}
