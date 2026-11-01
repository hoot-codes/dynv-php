<?php

declare(strict_types=1);

use Dynv\ChangeEvent;
use Dynv\Dynv;
use Dynv\DynvException;

require __DIR__ . '/../src/DynvException.php';
require __DIR__ . '/../src/ChangeEvent.php';
require __DIR__ . '/../src/Dynv.php';

function expectSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectTrue(bool $condition, string $message = 'condition is false'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectException(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new RuntimeException("expected {$class}, got " . $e::class, 0, $e);
        }
        return $e;
    }

    throw new RuntimeException("expected {$class}, nothing was thrown");
}

function initialSnapshot(): array
{
    $vars = [
        'PROXY_SERVER' => 'proxy-one',
        'SHARED_KEY' => 'shared-one',
        'DATABASE_URL' => 'postgres://db-one/app',
        'HOT_KEY' => 'hot-one',
        'REMOVE_ME' => 'remove-one',
        'AUTH_SECRET' => 'new-auth',
        'AUTH_SECRET_PREVIOUS' => 'old-auth',
    ];

    return [
        'version' => 1,
        'vars' => $vars,
        'apply' => [
            'PROXY_SERVER' => 'hot',
            'SHARED_KEY' => 'hot',
            'DATABASE_URL' => 'hot',
            'HOT_KEY' => 'hot',
            'REMOVE_ME' => 'restart',
            'AUTH_SECRET' => 'hot',
            'AUTH_SECRET_PREVIOUS' => 'hot',
        ],
        'managed' => [...array_keys($vars), 'TOMBSTONE', 'BROKEN_URL'],
        'sources' => ['SHARED_KEY' => 'backend'],
        'templates' => ['DATABASE_URL'],
        'unresolved' => ['BROKEN_URL'],
        'fallbacks' => ['AUTH_SECRET' => ['as' => 'AUTH_SECRET_PREVIOUS', 'values' => ['old-auth']]],
    ];
}

function nextSnapshot(): array
{
    $snapshot = initialSnapshot();
    $snapshot['version'] = 2;
    $snapshot['vars']['PROXY_SERVER'] = 'proxy-two';
    $snapshot['vars']['HOT_KEY'] = 'hot-two';
    unset($snapshot['vars']['REMOVE_ME'], $snapshot['apply']['REMOVE_ME']);

    return $snapshot;
}

function writeSnapshot(string $path, array $snapshot): void
{
    $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    $temporary = $path . '.tmp';
    file_put_contents($temporary, $json);
    rename($temporary, $path);
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("cannot allocate a port: {$error}");
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($name)) {
        throw new RuntimeException('cannot read the allocated port');
    }

    return (int) substr($name, strrpos($name, ':') + 1);
}

/** @return array{process: resource, url: string, log: string} */
function startFakeAgent(string $snapshotPath, bool $redirect = false): array
{
    $port = freePort();
    $log = $snapshotPath . ($redirect ? '.redirect.log' : '.log');
    $environment = getenv();
    $environment['DYNV_FAKE_SNAPSHOT'] = $snapshotPath;
    $environment['DYNV_FAKE_KEY'] = 'test-agent-key';
    if ($redirect) {
        $environment['DYNV_FAKE_REDIRECT'] = '1';
    } else {
        unset($environment['DYNV_FAKE_REDIRECT']);
    }

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake-agent.php'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ],
        $pipes,
        __DIR__,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start the fake agent');
    }
    fclose($pipes[0]);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $details = is_file($log) ? file_get_contents($log) : '';
            proc_close($process);
            throw new RuntimeException("fake agent exited: {$details}");
        }
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
        if (is_resource($socket)) {
            fclose($socket);
            return ['process' => $process, 'url' => 'http://127.0.0.1:' . $port, 'log' => $log];
        }
        usleep(20_000);
    }

    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('fake agent did not become ready');
}

/** @param array{process: resource, url: string, log: string} $server */
function stopFakeAgent(array $server): void
{
    proc_terminate($server['process']);
    $deadline = microtime(true) + 1.0;
    do {
        $status = proc_get_status($server['process']);
        if (!$status['running']) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($server['process'], 9);
    }
    proc_close($server['process']);
}

putenv('DYNV_AGENT_URL');
putenv('DYNV_AGENT_KEY');
unset($_ENV['DYNV_AGENT_URL'], $_ENV['DYNV_AGENT_KEY']);

$tempDirectory = sys_get_temp_dir() . '/dynv-php-tests-' . bin2hex(random_bytes(6));
if (!mkdir($tempDirectory, 0700, true)) {
    fwrite(STDERR, "cannot create the test directory\n");
    exit(1);
}
$snapshotPath = $tempDirectory . '/snapshot.json';
$servers = [];
$failures = 0;
$count = 0;

try {
    writeSnapshot($snapshotPath, initialSnapshot());
    $agent = startFakeAgent($snapshotPath);
    $servers[] = $agent;
    $redirect = startFakeAgent($snapshotPath, true);
    $servers[] = $redirect;
    $url = $agent['url'];

    $tests = [
        'agent reads, fallbacks, metadata and require' => function () use ($url): void {
            putenv('TOMBSTONE=inherited-tombstone');
            putenv('BROKEN_URL=inherited-broken');
            putenv('UNMANAGED_KEY=inherited-unmanaged');
            $dynv = Dynv::connect($url, 'test-agent-key', true, 1.0);

            expectSame('agent', $dynv->mode());
            expectTrue($dynv->live());
            expectSame(1, $dynv->version());
            expectSame(null, $dynv->error());
            expectSame('proxy-one', $dynv->get('PROXY_SERVER'));
            expectSame('shared-one', $dynv->require('SHARED_KEY'));
            expectSame(initialSnapshot()['vars'], $dynv->all());
            expectSame(['new-auth', 'old-auth'], $dynv->all('AUTH_SECRET'));
            expectSame(['proxy-one'], $dynv->all('PROXY_SERVER'));
            expectSame([], $dynv->all('MISSING_KEY'));
            expectSame(null, $dynv->get('TOMBSTONE'));
            expectSame('fallback', $dynv->get('TOMBSTONE', 'fallback'));
            expectSame('fallback', $dynv->get('BROKEN_URL', 'fallback'));
            expectSame('inherited-unmanaged', $dynv->get('UNMANAGED_KEY'));
            expectSame('fallback', $dynv->get('UNKNOWN_KEY', 'fallback'));
            expectSame('backend', $dynv->source('SHARED_KEY'));
            expectSame(null, $dynv->source('PROXY_SERVER'));
            expectSame(null, $dynv->source('UNKNOWN_KEY'));
            expectTrue($dynv->isTemplate('DATABASE_URL'));
            expectTrue(!$dynv->isTemplate('PROXY_SERVER'));
            expectSame(['BROKEN_URL'], $dynv->unresolved());

            $e = expectException(DynvException::class, fn () => $dynv->require('MISSING_KEY'));
            expectSame('dynv: MISSING_KEY is not set', $e->getMessage());
            $e = expectException(DynvException::class, fn () => $dynv->require('TOMBSTONE'));
            expectSame('dynv: TOMBSTONE is not set', $e->getMessage());
        },
        'refresh applies a new snapshot' => function () use ($url, $snapshotPath): void {
            writeSnapshot($snapshotPath, initialSnapshot());
            $dynv = Dynv::connect($url, 'test-agent-key', true, 1.0);
            writeSnapshot($snapshotPath, nextSnapshot());

            expectTrue($dynv->refresh());
            expectSame(2, $dynv->version());
            expectSame('proxy-two', $dynv->get('PROXY_SERVER'));
            expectSame(null, $dynv->get('REMOVE_ME'));
            expectSame(null, $dynv->source('REMOVE_ME'));
        },
        'bad bearer key returns 401 and require throws' => function () use ($url, $snapshotPath): void {
            writeSnapshot($snapshotPath, initialSnapshot());
            $e = expectException(
                DynvException::class,
                fn () => Dynv::connect($url, 'wrong-agent-key', true, 1.0),
            );
            expectSame('dynv: agent returned HTTP 401', $e->getMessage());
            expectTrue(!str_contains($e->getMessage(), 'wrong-agent-key'));

            $fallback = Dynv::connect($url, 'wrong-agent-key', false, 1.0);
            expectSame('env', $fallback->mode());
            expectTrue(!$fallback->live());
            expectSame('dynv: agent returned HTTP 401', $fallback->error());
        },
        // Ninth-pass H1: libcurl honours http_proxy/HTTPS_PROXY/ALL_PROXY, so a
        // proxy received the agent key and plaintext (and could forge them).
        'proxy environment variables are never used for the agent' => function () use ($url, $snapshotPath, $tempDirectory): void {
            writeSnapshot($snapshotPath, initialSnapshot());
            $proxyLog = $tempDirectory . '/proxy-requests.log';
            touch($proxyLog);
            $port = freePort();
            $environment = getenv();
            $environment['DYNV_PROXY_LOG'] = $proxyLog;
            $proxy = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/proxy-recorder.php'],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
                $pipes,
                __DIR__,
                $environment,
            );
            fclose($pipes[0]);
            $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
            try {
                $deadline = microtime(true) + 3.0;
                while (microtime(true) < $deadline && !is_resource($s = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05))) {
                    usleep(20_000);
                }
                foreach ($names as $name) {
                    putenv("{$name}=http://127.0.0.1:{$port}");
                }
                putenv('NO_PROXY');
                putenv('no_proxy');
                $dynv = Dynv::connect($url, 'test-agent-key', true, 1.0);
                expectSame('proxy-one', $dynv->get('PROXY_SERVER'), 'read directly from the agent');
                expectSame('', (string) file_get_contents($proxyLog), 'the proxy saw no request');
            } finally {
                foreach ($names as $name) {
                    putenv($name);
                }
                proc_terminate($proxy);
                proc_close($proxy);
            }
        },
        'non-loopback agent URL is refused' => function (): void {
            $e = expectException(
                DynvException::class,
                fn () => Dynv::connect('http://192.0.2.1:8080', 'key', true),
            );
            expectTrue(str_contains($e->getMessage(), 'loopback'));
            foreach (['http://u:p@127.0.0.1:1', 'http://127.0.0.1:1/x', 'http://127.0.0.1:1/?', 'http://127.0.0.1:1#', 'ftp://127.0.0.1:1'] as $bad) {
                expectException(DynvException::class, fn () => Dynv::connect($bad, 'key', true));
            }
        },
        'agent redirect is refused' => function () use ($redirect): void {
            $e = expectException(
                DynvException::class,
                fn () => Dynv::connect($redirect['url'], 'test-agent-key', true, 1.0),
            );
            expectSame('dynv: agent redirects are not allowed', $e->getMessage());
        },
        'env mode reads getenv and $_ENV' => function (): void {
            putenv('ENV_ONLY=inherited-env');
            putenv('ARRAY_ONLY');
            $_ENV['ARRAY_ONLY'] = 'from-_ENV';
            $dynv = Dynv::connect();

            expectSame('env', $dynv->mode());
            expectTrue(!$dynv->live());
            expectSame(0, $dynv->version());
            expectSame([], $dynv->all());
            expectSame(['inherited-env'], $dynv->all('ENV_ONLY'));
            expectSame([], $dynv->all('MISSING_ENV'));
            expectSame('inherited-env', $dynv->get('ENV_ONLY'));
            expectSame('from-_ENV', $dynv->get('ARRAY_ONLY'));
            expectSame('fallback', $dynv->get('MISSING_ENV', 'fallback'));
            expectTrue(!$dynv->refresh());
            expectSame(null, $dynv->error());
        },
        'watch loops after 204, emits changes and per-key values, then stops' => function () use ($url, $snapshotPath): void {
            writeSnapshot($snapshotPath, initialSnapshot());
            putenv('REMOVE_ME=inherited-remove');
            $dynv = Dynv::connect($url, 'test-agent-key', true, 1.0);
            $events = [];
            $proxyValue = 'not-called';
            $removedValue = 'not-called';
            $stopCalls = 0;
            $published = false;

            $dynv->on('PROXY_SERVER', function (?string $value) use (&$proxyValue): void {
                $proxyValue = $value;
            });
            $dynv->on('REMOVE_ME', function (?string $value) use (&$removedValue): void {
                $removedValue = $value;
            });
            $dynv->watch(
                function (ChangeEvent $event) use (&$events): void {
                    $events[] = $event;
                },
                function () use (&$stopCalls, &$published, &$events, $snapshotPath): bool {
                    ++$stopCalls;
                    if ($stopCalls === 2) {
                        writeSnapshot($snapshotPath, nextSnapshot());
                        $published = true;
                    }
                    return $events !== [];
                },
            );

            expectTrue($published);
            expectSame(3, $stopCalls);
            expectSame(1, count($events));
            expectSame(2, $events[0]->version);
            expectSame(['HOT_KEY', 'PROXY_SERVER', 'REMOVE_ME'], $events[0]->changed);
            expectTrue($events[0]->restart, 'removed restart-mode key must set restart');
            expectSame('proxy-two', $proxyValue);
            expectSame(null, $removedValue);
            expectSame(2, $dynv->version());
            expectSame(null, $dynv->get('REMOVE_ME'));
        },
    ];

    foreach ($tests as $name => $test) {
        ++$count;
        try {
            $test();
            echo "ok {$count} - {$name}\n";
        } catch (Throwable $e) {
            ++$failures;
            echo "not ok {$count} - {$name}\n";
            echo '  ' . $e::class . ': ' . $e->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    ++$failures;
    echo 'not ok - test setup: ' . $e->getMessage() . "\n";
} finally {
    foreach (array_reverse($servers) as $server) {
        stopFakeAgent($server);
    }
    foreach (glob($tempDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($tempDirectory);
}

echo "{$count} tests, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
