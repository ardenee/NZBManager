<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/AppConfig.php';

$path = $argv[1] ?? '';
$password = $argv[2] ?? 'test-install-password-5678';
if ($path === '' || !is_file($path) || !is_string($password))
{
    throw new RuntimeException('Usage: php package-auth.php <test-hash-file> <test-password>');
}
putenv('NZB_AUTH_HASH_FILE=' . $path);
$hash = \NZB\AppConfig::passwordHash();
if ($hash === '' || $hash === $password || !password_verify($password, $hash) || password_verify('wrong-password', $hash))
{
    throw new RuntimeException('Installer password hash did not authenticate correctly.');
}
echo "PASS DSM installer password is hashed and verifies correctly in PHP\n";
