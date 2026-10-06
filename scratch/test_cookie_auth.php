<?php

// Teste de autenticação via Cookie HttpOnly
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Auth\User;
use Illuminate\Http\Request;

echo "--- Teste de Cookie HttpOnly Auth ---".PHP_EOL;

// 1. Login
$requestLogin = Request::create('/api/login', 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
], json_encode([
    'email' => 'admin@sgde.pt',
    'password' => 'admin123',
]));

$respLogin = $kernel->handle($requestLogin);
$kernel->terminate($requestLogin, $respLogin);

$cookies = $respLogin->headers->getCookies();
$accessTokenCookie = null;
foreach ($cookies as $c) {
    if ($c->getName() === 'access_token') {
        $accessTokenCookie = $c;
        break;
    }
}

$loginOk = $respLogin->getStatusCode() === 200 && $accessTokenCookie !== null && $accessTokenCookie->isHttpOnly();
echo ($loginOk ? "[OK]   " : "[FAIL] "). "Login define cookie HttpOnly 'access_token'".PHP_EOL;
if (!$loginOk) {
    echo "Status: " . $respLogin->getStatusCode() . PHP_EOL;
    echo "Content: " . $respLogin->getContent() . PHP_EOL;
    exit(1);
}

// 2. Chamar /api/me enviando APENAS o cookie (sem header Authorization Bearer!)
app('auth')->forgetGuards();

$cookieValue = $accessTokenCookie->getValue();
$requestMe = Request::create('/api/me', 'GET', [], ['access_token' => $cookieValue], [], [
    'HTTP_ACCEPT' => 'application/json',
]);

$respMe = $kernel->handle($requestMe);
$kernel->terminate($requestMe, $respMe);

$bodyMe = json_decode($respMe->getContent(), true);
$meOk = $respMe->getStatusCode() === 200 && ($bodyMe['data']['email'] ?? null) === 'admin@sgde.pt';
echo ($meOk ? "[OK]   " : "[FAIL] "). "GET /api/me autentica com sucesso apenas com o Cookie HttpOnly".PHP_EOL;
if (!$meOk) {
    echo "Status /api/me: " . $respMe->getStatusCode() . PHP_EOL;
    echo "Content: " . $respMe->getContent() . PHP_EOL;
}

// 3. Logout com cookie
app('auth')->forgetGuards();
$requestLogout = Request::create('/api/logout', 'POST', [], ['access_token' => $cookieValue], [], [
    'HTTP_ACCEPT' => 'application/json',
]);
$respLogout = $kernel->handle($requestLogout);
$kernel->terminate($requestLogout, $respLogout);

$logoutCookies = $respLogout->headers->getCookies();
$cookieCleared = false;
foreach ($logoutCookies as $c) {
    if ($c->getName() === 'access_token' && ($c->getExpiresTime() < time() || $c->getValue() === null)) {
        $cookieCleared = true;
        break;
    }
}

$logoutOk = $respLogout->getStatusCode() === 200 && $cookieCleared;
echo ($logoutOk ? "[OK]   " : "[FAIL] "). "POST /api/logout limpa o cookie HttpOnly".PHP_EOL;

echo "--- Fim do Teste ---".PHP_EOL;

