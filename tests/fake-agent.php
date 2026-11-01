<?php

declare(strict_types=1);

function fakeSnapshot(): array
{
    $path = getenv('DYNV_FAKE_SNAPSHOT');
    if (!is_string($path) || $path === '') {
        http_response_code(500);
        exit;
    }

    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function fakeRespond(array $snapshot): void
{
    header('Content-Type: application/json');
    echo json_encode($snapshot, JSON_THROW_ON_ERROR);
    exit;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$key = getenv('DYNV_FAKE_KEY') ?: '';
if (!hash_equals('Bearer ' . $key, $authorization)) {
    http_response_code(401);
    exit;
}

if (getenv('DYNV_FAKE_REDIRECT') === '1') {
    header('Location: http://127.0.0.1:1/v1/env', true, 302);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/v1/env') {
    fakeRespond(fakeSnapshot());
}
if ($path !== '/v1/env/watch') {
    http_response_code(404);
    exit;
}

$since = isset($_GET['since']) && ctype_digit((string) $_GET['since'])
    ? (int) $_GET['since']
    : -1;
$deadline = microtime(true) + 2.0;
while (true) {
    $snapshot = fakeSnapshot();
    if (($snapshot['version'] ?? null) !== $since) {
        fakeRespond($snapshot);
    }
    if (microtime(true) >= $deadline) {
        http_response_code(204);
        exit;
    }
    usleep(20_000);
}
