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

        $passwordlessDsm = AppConfig::passwordlessDsm();
        $password = getenv('NZB_ADMIN_PASSWORD') ?: '';
        $hash = AppConfig::passwordHash();
        if (!$passwordlessDsm && $password === '' && $hash === '')
        {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('NZB login is not configured for this Linux deployment. Set NZB_ADMIN_PASSWORD_HASH or NZB_ADMIN_PASSWORD.');
        }

        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $provided = $_SERVER['PHP_AUTH_PW'] ?? null;
        if (!is_string($username) || !is_string($provided))
        {
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (is_string($header) && str_starts_with($header, 'Basic ') && strlen($header) < 4096)
            {
                $decoded = base64_decode(substr($header, 6), true);
                if (is_string($decoded) && str_contains($decoded, ':'))
                {
                    $username = substr($decoded, 0, strpos($decoded, ':'));
                    $provided = substr($decoded, strpos($decoded, ':') + 1);
                }
            }
        }
        $username = is_string($username) ? $username : '';
        $provided = is_string($provided) ? $provided : '';
        $expectedUser = getenv('NZB_ADMIN_USERNAME') ?: 'admin';
        $allowed = hash_equals($expectedUser, $username) &&
            ($hash !== '' ? password_verify($provided, $hash) : hash_equals($password, $provided));
        if (!$passwordlessDsm && !$allowed)
        {
            header('WWW-Authenticate: Basic realm="NZB Manager", charset="UTF-8"');
            http_response_code(401);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Authentication required.');
        }

        $private = AppConfig::privateDir();
        if ($private !== null && is_dir($private . '/sessions'))
        {
            ini_set('session.save_path', $private . '/sessions');
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
