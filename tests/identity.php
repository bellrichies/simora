<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Domain\Identity\AccessPolicy;
use App\Domain\Identity\Credentials;
use App\Domain\Identity\Csrf;
use App\Domain\Identity\SessionToken;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$csrf = new Csrf();
$token = $csrf->issue();
$check($csrf->verify($token, $token), 'Valid CSRF');
$check(!$csrf->verify($token, $csrf->issue()) && !$csrf->verify('', ''), 'CSRF fail closed');
$session = new SessionToken();
$one = $session->issue();
$check(strlen($session->digest($one)) === 32 && $session->digest($one) !== $session->digest($session->issue()), 'Session digest');
$policy = new AccessPolicy();
$check($policy->owns(7, 7) && !$policy->owns(7, 8) && !$policy->owns(0, 0), 'Ownership');
$check($policy->allows(['settings.read'], 'settings.read') && !$policy->allows(['settings.read'], 'settings.write'), 'Permission');
$credentials = new Credentials();
$check($credentials->normalizeEmail(' Person@Example.com ') === 'person@example.com', 'Email normalization');
$hash = $credentials->hashPassword('a secure long passphrase');
$check($credentials->verifyPassword('a secure long passphrase', $hash) && !$credentials->verifyPassword('wrong', $hash), 'Password verification');
foreach ([static fn () => $credentials->hashPassword('short'), static fn () => $credentials->normalizeEmail('invalid'), static fn () => $session->digest('bad')] as $invalid) {
    try {
        $invalid();
        throw new RuntimeException('Invalid input accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
echo "Identity primitive tests passed\n";
