<?php

declare(strict_types=1);

use Dynv\Dynv;

require __DIR__ . '/../src/DynvException.php';
require __DIR__ . '/../src/ChangeEvent.php';
require __DIR__ . '/../src/Dynv.php';

$dynv = Dynv::connect();

header('Content-Type: application/json');
echo json_encode([
    'pid' => getmypid(),
    'mode' => $dynv->mode(),
    'version' => $dynv->version(),
    'proxy' => $dynv->get('PROXY_SERVER'),
    'shared' => $dynv->get('SHARED_KEY'),
    'sharedSource' => $dynv->source('SHARED_KEY'),
    'databaseUrl' => $dynv->get('DATABASE_URL'),
    'databaseUrlIsTemplate' => $dynv->isTemplate('DATABASE_URL'),
    'unresolved' => $dynv->unresolved(),
    'envProxy' => getenv('PROXY_SERVER') ?: null,
    'tokenVisible' => getenv('DYNV_TOKEN') !== false,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
