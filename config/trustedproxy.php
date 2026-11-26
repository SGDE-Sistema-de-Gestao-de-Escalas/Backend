<?php

/*
|--------------------------------------------------------------------------
| Proxies de confiança (F-05)
|--------------------------------------------------------------------------
|
| Atrás de um proxy (Nginx) o IP real vem em X-Forwarded-For. Só se confia
| no cabeçalho se o pedido vier de um proxy desta lista, senão qualquer
| cliente podia inventar o IP e fugir aos limites de pedidos.
|
| TRUSTED_PROXIES: IPs separados por vírgulas, ou "*" (só se a aplicação
| não for acessível diretamente).
|
*/

$trusted = trim((string) env('TRUSTED_PROXIES', '127.0.0.1'));

return [
    'proxies' => $trusted === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', $trusted)))),
];
