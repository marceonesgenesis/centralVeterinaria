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
        'PrescriptionForm::onClear',
        'SystemSQLPanel::onGenerate',
        'SystemSQLPanel::onExportCSV',
        'SystemDataBrowser::onLoad',
        'SystemTableList::onExportCSV',
        'SystemTableList::onExportSQL',
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

        // PHP resolves class and method names case-insensitively, so every
        // comparison is too: `onClearcart` must not dodge `onClearCart`.
        if (self::inList($class . '::' . $method, self::MUTATING_OVERRIDES)) {
            return false;
        }

        if (self::inList($method, self::READ_METHODS)) {
            return true;
        }

        foreach (self::READ_PREFIXES as $prefix) {
            if (strncasecmp($method, $prefix, strlen($prefix)) === 0) {
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
            || self::inList($class, self::EXEMPT_CLASSES)
            || self::inList($class . '::' . $method, self::EXEMPT_ACTIONS)
        ) {
            return self::ALLOW;
        }

        if ($entryScript === 'index.php') {
            return self::STRIP_METHOD;
        }

        return CsrfToken::isValid($httpMethod, $sentToken, $sessionToken) ? self::ALLOW : self::DENY;
    }

    /**
     * Decides on the live request. Class and method come from $_REQUEST (what
     * AdiantiCoreApplication::run() and static dispatch use), but the page
     * dispatch (AdiantiPageControlTrait::run) executes $_GET: when the query or
     * the body name a class/method different from $_REQUEST, the request is
     * denied. Names are then resolved to their canonical spelling (Reflection
     * by default); unknown class/method or a spelling that differs from the
     * declared one is denied.
     *
     * @param array<string, mixed>      $server   $_SERVER
     * @param array<string, mixed>      $request  $_REQUEST
     * @param array<string, mixed>|null $query    $_GET (null = the superglobal)
     * @param array<string, mixed>|null $body     $_POST (null = the superglobal)
     * @param (callable(string, string): ?array{0: string, 1: string})|null $resolver
     */
    public static function decideFromRequest(
        array $server,
        array $request,
        mixed $sessionToken,
        ?array $query = null,
        ?array $body = null,
        ?callable $resolver = null
    ): string {
        $query ??= $_GET;
        $body ??= $_POST;

        $script = $server['SCRIPT_NAME'] ?? '';
        $httpMethod = $server['REQUEST_METHOD'] ?? 'GET';
        $class = self::stringParam($request, 'class');
        $method = self::stringParam($request, 'method');

        foreach (['class' => $class, 'method' => $method] as $key => $value) {
            foreach ([$query, $body] as $source) {
                if (array_key_exists($key, $source) && self::stringParam($source, $key) !== $value) {
                    return self::DENY;
                }
            }
        }

        // The page dispatch runs $_GET['method']: a method present only in
        // $_REQUEST (body or cookie) while the query names the class is not
        // what will run.
        if ($method !== '' && array_key_exists('class', $query) && !array_key_exists('method', $query)
            && !array_key_exists('method', $body)) {
            return self::DENY;
        }

        if ($class === '') {
            if ($method !== '') {
                return self::DENY;
            }
        } else {
            $canonical = ($resolver ?? [self::class, 'resolveCanonical'])($class, $method);

            if (!is_array($canonical) || $canonical !== [$class, $method]) {
                return self::DENY;
            }
        }

        return self::decide(
            basename(is_string($script) ? $script : ''),
            is_string($httpMethod) ? $httpMethod : 'GET',
            $class,
            $method,
            $server[self::SERVER_HEADER_KEY] ?? null,
            $sessionToken
        );
    }

    /**
     * Canonical (declared) class and method names, or null when the class
     * or the method does not exist.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function resolveCanonical(string $class, string $method): ?array
    {
        try {
            if (!class_exists($class)) {
                return null;
            }

            $canonicalClass = (new \ReflectionClass($class))->getName();

            if ($method === '') {
                return [$canonicalClass, ''];
            }

            if (!method_exists($canonicalClass, $method)) {
                return null;
            }

            return [$canonicalClass, (new \ReflectionMethod($canonicalClass, $method))->getName()];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $source */
    private static function stringParam(array $source, string $key): string
    {
        $value = $source[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @param list<string> $list */
    private static function inList(string $needle, array $list): bool
    {
        foreach ($list as $item) {
            if (strcasecmp($needle, $item) === 0) {
                return true;
            }
        }

        return false;
    }
}
