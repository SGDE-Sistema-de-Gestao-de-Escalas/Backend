<?php

/*
|--------------------------------------------------------------------------
| Limites de pedidos (F-05 e F-06)
|--------------------------------------------------------------------------
|
| Todos os valores são "pedidos por minuto" e podem ser ajustados no .env sem
| mexer no código. As chaves dos limites estão em AppServiceProvider.
|
| Numa escola, vários computadores saem para a internet pelo mesmo IP, por isso
| o login é limitado por email + IP (cada pessoa tem o seu limite) e só depois,
| de forma mais folgada, pelo IP inteiro.
|
*/

return [
    // Login: tentativas por email + IP, e por IP (contra quem testa muitos emails).
    'login' => [
        'per_email' => (int) env('RATE_LIMIT_LOGIN_PER_EMAIL', 5),
        'per_ip' => (int) env('RATE_LIMIT_LOGIN_PER_IP', 60),
    ],

    // "Esqueci-me da password": pedidos por email + IP, e por IP.
    'password_forgot' => [
        'per_email' => (int) env('RATE_LIMIT_FORGOT_PER_EMAIL', 5),
        'per_ip' => (int) env('RATE_LIMIT_FORGOT_PER_IP', 20),
    ],

    // Repor a password com o token recebido por email: por IP.
    'password_reset' => [
        'per_ip' => (int) env('RATE_LIMIT_RESET_PER_IP', 10),
    ],

    // Login social (redirecionar e voltar do fornecedor): por IP.
    'oauth' => (int) env('RATE_LIMIT_OAUTH_PER_IP', 30),

    // API autenticada: por utilizador. Uma página carrega várias listas de uma vez.
    'api' => (int) env('RATE_LIMIT_API_PER_USER', 240),

    // Operações pesadas (gerar escalas, importar/exportar CSV): por utilizador.
    'heavy' => (int) env('RATE_LIMIT_HEAVY_PER_USER', 10),
];
