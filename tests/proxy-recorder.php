<?php

declare(strict_types=1);

// A stand-in HTTP proxy for the tests: records every request it receives
// (it must receive none: the agent key and plaintext never go to a proxy)
// and refuses it.
file_put_contents((string) getenv('DYNV_PROXY_LOG'), ($_SERVER['REQUEST_URI'] ?? '?') . "\n", FILE_APPEND);
http_response_code(502);
echo 'proxied';
