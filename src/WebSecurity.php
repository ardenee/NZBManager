<?php
declare(strict_types=1);
namespace NZB;

final class WebSecurity
{
    public static function initialize(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

        $password = getenv('NZB_ADMIN_PASSWORD') ?: '';
        $hash = getenv('NZB_ADMIN_PASSWORD_HASH') ?: '';
        if ($password === '' && $hash === '')
        {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('NZB is not configured. Set NZB_ADMIN_PASSWORD_HASH or NZB_ADMIN_PASSWORD.');
        }

        $provided = $_SERVER['PHP_AUTH_PW'] ?? '';
        $allowed = $hash !== '' ? password_verify($provided, $hash) : hash_equals($password, $provided);
        if (!$allowed)
        {
            header('WWW-Authenticate: Basic realm="NZB Manager", charset="UTF-8"');
            http_response_code(401);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Authentication required.');
        }

        session_name('NZBSESSID');
        session_set_cookie_params([
            'httponly' => true, 'samesite' => 'Strict', 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        ]);
        session_start();
        if (!isset($_SESSION['csrf']))
        {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrf(): string
    {
        return (string) $_SESSION['csrf'];
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            http_response_code(405);
            exit('Method not allowed.');
        }
        $token = $_POST['_csrf'] ?? null;
        if (!is_string($token) || !hash_equals(self::csrf(), $token))
        {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }
    }
}
