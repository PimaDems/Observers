<?php
declare(strict_types=1);

final class Security
{
    public static function startSession(string $name = 'obs_sess'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Util::url(''),
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . Util::h(self::csrfToken()) . '">';
    }

    public static function csrfOk(?string $sent = null): bool
    {
        self::startSession();
        $sent ??= (string) ($_POST['csrf'] ?? '');
        return !empty($_SESSION['csrf']) && $sent !== '' && hash_equals($_SESSION['csrf'], $sent);
    }

    /** Abort the request unless it is a POST with a valid CSRF token. */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !self::csrfOk()) {
            http_response_code(400);
            exit('Invalid request (CSRF check failed). Go back, reload the page and try again.');
        }
    }

    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://*.tile.openstreetmap.org; "
            . "style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'");
    }
}
