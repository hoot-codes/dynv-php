<?php

declare(strict_types=1);

namespace Dynv;

use JsonException;

final class Dynv
{
    private string $mode = 'env';
    private ?string $agentUrl = null;
    private string $agentKey = '';
    private float $timeout = 5.0;
    private ?string $error = null;
    private bool $hasSnapshot = false;

    /** @var array{version: int, vars: array<string, string>, apply: array<string, string>, managed: list<string>, sources: array<string, string>, templates: list<string>, unresolved: list<string>, fallbacks: array<string, array{as: string, values: list<string>}>} */
    private array $snapshot = [
        'version' => 0,
        'vars' => [],
        'apply' => [],
        'managed' => [],
        'sources' => [],
        'templates' => [],
        'unresolved' => [],
        'fallbacks' => [],
    ];

    /** @var array<string, true> Every key managed in this run, including removals. */
    private array $managed = [];

    /** @var array<string, list<callable(?string): void>> */
    private array $listeners = [];

    private function __construct()
    {
    }

    public static function connect(
        ?string $agentUrl = null,
        ?string $agentKey = null,
        bool $require = false,
        float $timeout = 5.0,
    ): Dynv {
        $agentUrl ??= self::environment('DYNV_AGENT_URL');
        $dynv = new self();

        if ($agentUrl === null || $agentUrl === '') {
            return $dynv;
        }

        self::validateAgentUrl($agentUrl);
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new DynvException('dynv: timeout must be finite and greater than zero');
        }

        $dynv->agentUrl = rtrim($agentUrl, '/');
        $dynv->agentKey = $agentKey ?? self::environment('DYNV_AGENT_KEY') ?? '';
        $dynv->timeout = $timeout;

        try {
            $snapshot = $dynv->fetch('/v1/env', $timeout);
            if ($snapshot === null) {
                throw new DynvException('dynv: agent returned no snapshot');
            }
            $dynv->apply($snapshot);
            $dynv->hasSnapshot = true;
            $dynv->mode = 'agent';
        } catch (DynvException $e) {
            if ($require) {
                throw $e;
            }
            $dynv->error = $e->getMessage();
        }

        return $dynv;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->snapshot['vars'])) {
            return $this->snapshot['vars'][$key];
        }
        if (isset($this->managed[$key])) {
            return $default;
        }

        return self::environment($key) ?? $default;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new DynvException("dynv: {$key} is not set");
        }

        return $value;
    }

    public function source(string $key): ?string
    {
        if (!array_key_exists($key, $this->snapshot['vars'])) {
            return null;
        }

        return $this->snapshot['sources'][$key] ?? null;
    }

    public function isTemplate(string $key): bool
    {
        return in_array($key, $this->snapshot['templates'], true);
    }

    /** @return list<string> */
    public function unresolved(): array
    {
        return $this->snapshot['unresolved'];
    }

    public function version(): int
    {
        return $this->snapshot['version'];
    }

    /** @return 'agent'|'env' */
    public function mode(): string
    {
        return $this->mode;
    }

    public function live(): bool
    {
        return $this->mode === 'agent';
    }

    /**
     * With a key, return its current value followed by live fallback values,
     * newest first. Without one, retain the original all-variables behavior.
     *
     * @return array<string, string>|list<string>
     */
    public function all(?string $key = null): array
    {
        if ($key === null) {
            return $this->snapshot['vars'];
        }
        $current = $this->get($key);
        if ($current === null) {
            return [];
        }

        return [$current, ...($this->snapshot['fallbacks'][$key]['values'] ?? [])];
    }

    /** The last connection or request error, without response bodies or values. */
    public function error(): ?string
    {
        return $this->error;
    }

    public function refresh(): bool
    {
        if ($this->agentUrl === null) {
            return false;
        }

        try {
            $snapshot = $this->fetch('/v1/env', $this->timeout);
            if ($snapshot === null) {
                throw new DynvException('dynv: agent returned no snapshot');
            }
            $this->apply($snapshot);
            $this->hasSnapshot = true;
            $this->mode = 'agent';
            $this->error = null;
            return true;
        } catch (DynvException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * Listen for changes to one key. The callback runs inside watch() and gets
     * the new value, or null when the key was removed.
     *
     * @param callable(?string): void $fn
     */
    public function on(string $key, callable $fn): void
    {
        $this->listeners[$key][] = $fn;
    }

    /**
     * Block while long-polling the agent. The optional stop callback is checked
     * before each request and should return true to end the loop.
     *
     * @param callable(ChangeEvent): void $onChange
     * @param null|callable(): bool $stop
     */
    public function watch(callable $onChange, ?callable $stop = null): void
    {
        if ($this->agentUrl === null) {
            return;
        }

        $backoff = 0.5;
        while ($stop === null || !$stop()) {
            try {
                $snapshot = $this->fetch('/v1/env/watch?since=' . $this->version(), 40.0);
                $this->error = null;
                $backoff = 0.5;
                if ($snapshot === null) {
                    continue;
                }

                $hadSnapshot = $this->hasSnapshot;
                $event = $this->apply($snapshot);
                $this->hasSnapshot = true;
                $this->mode = 'agent';
                if (!$hadSnapshot || $event === null) {
                    continue;
                }

                $onChange($event);
                foreach ($event->changed as $key) {
                    foreach ($this->listeners[$key] ?? [] as $listener) {
                        $listener($this->snapshot['vars'][$key] ?? null);
                    }
                }
            } catch (DynvException $e) {
                $this->error = $e->getMessage();
                usleep((int) ($backoff * 1_000_000));
                $backoff = min($backoff * 2, 30.0);
            }
        }
    }

    /** @return array{version: int, vars: array<string, string>, apply: array<string, string>, managed: list<string>, sources: array<string, string>, templates: list<string>, unresolved: list<string>, fallbacks: array<string, array{as: string, values: list<string>}>}|null */
    private function fetch(string $path, float $timeout): ?array
    {
        if (function_exists('curl_init')) {
            return $this->fetchWithCurl($path, $timeout);
        }

        return $this->fetchWithStream($path, $timeout);
    }

    /** @return array{version: int, vars: array<string, string>, apply: array<string, string>, managed: list<string>, sources: array<string, string>, templates: list<string>, unresolved: list<string>, fallbacks: array<string, array{as: string, values: list<string>}>}|null */
    private function fetchWithCurl(string $path, float $timeout): ?array
    {
        $handle = curl_init($this->agentUrl . $path);
        if ($handle === false) {
            throw new DynvException('dynv: cannot initialize the agent request');
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->agentKey,
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT_MS => self::milliseconds($timeout),
                CURLOPT_TIMEOUT_MS => self::milliseconds($timeout),
                // Never a proxy (http_proxy, HTTPS_PROXY, ALL_PROXY…): the agent
                // is loopback, and a proxy would see the agent key and the
                // plaintext, and could forge snapshots.
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_NOSIGNAL => true,
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new DynvException('dynv: cannot reach the dynv run agent');
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return self::response($status, $body);
        } finally {
            curl_close($handle);
        }
    }

    /** @return array{version: int, vars: array<string, string>, apply: array<string, string>, managed: list<string>, sources: array<string, string>, templates: list<string>, unresolved: list<string>, fallbacks: array<string, array{as: string, values: list<string>}>}|null */
    private function fetchWithStream(string $path, float $timeout): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->agentKey,
                    'Accept: application/json',
                    'Connection: close',
                ]),
                'timeout' => $timeout,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                // No 'proxy' option: PHP's http stream wrapper never takes a
                // proxy from the environment (unlike libcurl, above), and this
                // context is the only one used for the request.
            ],
        ]);
        $http_response_header = [];
        $body = @file_get_contents($this->agentUrl . $path, false, $context);
        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $http_response_header[0], $matches) === 1) {
            $status = (int) $matches[1];
        }

        if ($body === false) {
            if ($status >= 300 && $status < 400) {
                throw new DynvException('dynv: agent redirects are not allowed');
            }
            if ($status > 0) {
                throw new DynvException("dynv: agent returned HTTP {$status}");
            }
            throw new DynvException('dynv: cannot reach the dynv run agent');
        }

        return self::response($status, $body);
    }

    private static function milliseconds(float $timeout): int
    {
        return max(1, (int) ceil($timeout * 1000));
    }

    /** @return array{version: int, vars: array<string, string>, apply: array<string, string>, managed: list<string>, sources: array<string, string>, templates: list<string>, unresolved: list<string>, fallbacks: array<string, array{as: string, values: list<string>}>}|null */
    private static function response(int $status, string $body): ?array
    {
        if ($status === 204) {
            return null;
        }
        if ($status >= 300 && $status < 400) {
            throw new DynvException('dynv: agent redirects are not allowed');
        }
        if ($status !== 200) {
            throw new DynvException("dynv: agent returned HTTP {$status}");
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DynvException('dynv: agent sent a malformed snapshot');
        }

        return self::snapshot($data);
    }

    /**
     * @param mixed $data
     * @return array{version: int, vars: array<string, string>, apply: array<string, string>, managed: list<string>, sources: array<string, string>, templates: list<string>, unresolved: list<string>, fallbacks: array<string, array{as: string, values: list<string>}>}
     */
    private static function snapshot(mixed $data): array
    {
        if (!is_array($data)
            || !isset($data['version']) || !is_int($data['version']) || $data['version'] < 0
            || !isset($data['vars']) || !is_array($data['vars'])
            || !isset($data['apply']) || !is_array($data['apply'])) {
            throw new DynvException('dynv: agent sent a malformed snapshot');
        }

        foreach (['vars', 'apply', 'sources'] as $field) {
            $values = $data[$field] ?? [];
            if (!is_array($values)) {
                throw new DynvException('dynv: agent sent a malformed snapshot');
            }
            foreach ($values as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new DynvException('dynv: agent sent a malformed snapshot');
                }
            }
        }
        foreach (['managed', 'templates', 'unresolved'] as $field) {
            $values = $data[$field] ?? [];
            if (!is_array($values)) {
                throw new DynvException('dynv: agent sent a malformed snapshot');
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new DynvException('dynv: agent sent a malformed snapshot');
                }
            }
        }
        $fallbacks = $data['fallbacks'] ?? [];
        if (!is_array($fallbacks)) {
            throw new DynvException('dynv: agent sent a malformed snapshot');
        }
        foreach ($fallbacks as $key => $fallback) {
            if (!is_string($key) || !is_array($fallback)
                || !isset($fallback['as'], $fallback['values']) || !is_string($fallback['as'])
                || !is_array($fallback['values'])) {
                throw new DynvException('dynv: agent sent a malformed snapshot');
            }
            foreach ($fallback['values'] as $value) {
                if (!is_string($value)) {
                    throw new DynvException('dynv: agent sent a malformed snapshot');
                }
            }
            $fallbacks[$key]['values'] = array_values($fallback['values']);
        }

        return [
            'version' => $data['version'],
            'vars' => $data['vars'],
            'apply' => $data['apply'],
            'managed' => array_values($data['managed'] ?? []),
            'sources' => $data['sources'] ?? [],
            'templates' => array_values($data['templates'] ?? []),
            'unresolved' => array_values($data['unresolved'] ?? []),
            'fallbacks' => $fallbacks,
        ];
    }

    /**
     * @param array{version: int, vars: array<string, string>, apply: array<string, string>, managed: list<string>, sources: array<string, string>, templates: list<string>, unresolved: list<string>, fallbacks: array<string, array{as: string, values: list<string>}>} $next
     */
    private function apply(array $next): ?ChangeEvent
    {
        $previous = $this->snapshot;
        if ($next['version'] === $previous['version']) {
            $this->snapshot = $next;
            foreach (array_merge($next['managed'], array_keys($next['vars'])) as $key) {
                $this->managed[$key] = true;
            }
            return null;
        }

        $keys = array_unique(array_merge(array_keys($previous['vars']), array_keys($next['vars'])));
        $changed = [];
        foreach ($keys as $key) {
            $before = $previous['vars'][$key] ?? null;
            $after = $next['vars'][$key] ?? null;
            if ($before !== $after || array_key_exists($key, $previous['vars']) !== array_key_exists($key, $next['vars'])) {
                $changed[] = $key;
            }
        }
        sort($changed, SORT_STRING);

        $restart = false;
        foreach ($changed as $key) {
            $modes = array_key_exists($key, $next['vars']) ? $next['apply'] : $previous['apply'];
            if (($modes[$key] ?? 'restart') === 'restart') {
                $restart = true;
                break;
            }
        }

        $this->snapshot = $next;
        foreach (array_merge($next['managed'], array_keys($next['vars'])) as $key) {
            $this->managed[$key] = true;
        }

        return new ChangeEvent($next['version'], $changed, $restart);
    }

    private static function environment(string $key): ?string
    {
        $value = getenv($key);
        if (is_string($value)) {
            return $value;
        }
        $value = $_ENV[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private static function validateAgentUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true)
            || !is_string($host)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || str_contains($url, '?') || str_contains($url, '#')
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || !self::isLoopback($host)) {
            throw new DynvException('dynv: DYNV_AGENT_URL must be an http(s) loopback URL (no credentials, path, query or fragment)');
        }
    }

    private static function isLoopback(string $host): bool
    {
        if (strtolower($host) === 'localhost') {
            return true;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && inet_pton($host) === inet_pton('::1');
    }
}
