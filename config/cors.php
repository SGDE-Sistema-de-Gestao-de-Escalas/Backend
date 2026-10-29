<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Origens autorizadas a chamar a API pelo browser. Com CORS_ALLOWED_ORIGINS vazio
    // (ou em falta) usa o FRONTEND_URL. Nunca usar "*": a API usa cookies com credenciais.
    'allowed_origins' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS') ?: env('FRONTEND_URL', 'http://localhost:5173'))
    )))),

    'allowed_origins_patterns' => [],

    // Se o frontend passar a enviar um cabeçalho novo, tem de ser acrescentado aqui.
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-School-ID', 'X-XSRF-TOKEN'],

    // O frontend lê o nome do ficheiro exportado em Content-Disposition.
    'exposed_headers' => ['Content-Disposition'],

    // O browser guarda a resposta ao pedido de verificação (OPTIONS) durante 1 hora.
    'max_age' => 3600,

    'supports_credentials' => true,

];
