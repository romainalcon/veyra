<?php

/*
 * Router for `php -S`, used by RealHttpProbeTest. It records the Host header
 * so the test can prove the request reached the pinned address with the
 * original host name.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('X-Seen-Host: '.($_SERVER['HTTP_HOST'] ?? ''));

match ($path) {
    '/ok' => http_response_code(204),
    '/redirect' => header('Location: /ok', true, 302),
    '/error' => (function (): void {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Set-Cookie: session=real-secret');
        echo '{"error":"database unavailable"}';
    })(),
    '/large' => (function (): void {
        header('Content-Length: '.(2 * 1024 * 1024));
        echo str_repeat('a', 2 * 1024 * 1024);
    })(),
    default => http_response_code(404),
};
