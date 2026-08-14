<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The browser's way to Solana.
 *
 * Solana's public cluster answers a server and refuses a browser: the same
 * call that returns a balance from this host comes back `403 Access forbidden`
 * as soon as it carries an `Origin` header. Every browser-side Solana read in
 * this app — the wallet's account, the bridge's SPL balances, staking — hit
 * that wall at the same time. The keyed endpoints do answer browsers, but only
 * with the key in the URL, and a key shipped in a bundle is a key anyone may
 * spend.
 *
 * So this relay forwards JSON-RPC and nothing else. It holds no Solana key,
 * signs nothing and stores nothing about who called: a transaction arrives
 * already signed by the browser or by Phantom, and the only thing this adds on
 * the way out is the credential that identifies *this app* to the upstream.
 *
 * What it does decide is what may be asked (`config/solana.php` names the
 * methods), how often (the route's throttle), and which endpoint answers —
 * upstreams are tried in order, so an exhausted key falls through to the
 * public cluster instead of taking Solana down with it.
 */
class SolanaRpcProxy
{
    public const DEFAULT_CLUSTER = 'mainnet';

    /**
     * JSON-RPC error codes that mean "not from me, ask elsewhere" rather than
     * "here is your answer": an unhealthy or rate-limited node, and a refusal.
     * Anything else the upstream says is a real answer and is passed back.
     */
    private const REFUSAL_CODES = [-32005, 403, 429];

    /** Whether the relay is switched on at all. */
    public function enabled(): bool
    {
        return (bool) config('solana.rpc.enabled', true)
            && $this->upstreams(self::DEFAULT_CLUSTER) !== [];
    }

    /** Clusters this relay is configured to reach. */
    public function clusters(): array
    {
        return array_keys((array) config('solana.rpc.upstreams', []));
    }

    public function knows(string $cluster): bool
    {
        return in_array($cluster, $this->clusters(), true);
    }

    /**
     * The absolute endpoint a browser should be handed for a cluster.
     *
     * `$fallback` is what the caller used before this relay existed — the
     * public cluster, usually — and is what comes back when the relay is off,
     * so switching it off is a return to the previous behaviour rather than a
     * page with no endpoint at all.
     */
    public function browserEndpoint(string $cluster = self::DEFAULT_CLUSTER, string $fallback = ''): string
    {
        if (! $this->enabled() || ! $this->knows($cluster)) {
            return $fallback;
        }

        return url('/api/solana/rpc'.($cluster === self::DEFAULT_CLUSTER ? '' : '/'.$cluster));
    }

    /**
     * @return array<int, string>
     */
    public function upstreams(string $cluster): array
    {
        /** @var array<int, string> $upstreams */
        $upstreams = (array) config('solana.rpc.upstreams.'.$cluster, []);

        return array_values(array_filter($upstreams, fn ($url) => is_string($url) && $url !== ''));
    }

    public function allows(string $method): bool
    {
        return in_array($method, (array) config('solana.rpc.methods', []), true);
    }

    /**
     * Forward one JSON-RPC call and return the upstream's decoded answer.
     *
     * Cacheable methods are answered from the cache when one is warm. Only
     * chain-wide reads are ever cached (see `config/solana.php`) and only their
     * `result` is kept, so the `id` that comes back is always the caller's own.
     *
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when no upstream answered
     */
    public function forward(array $call, string $cluster = self::DEFAULT_CLUSTER): array
    {
        $ttl = $this->cacheSeconds((string) ($call['method'] ?? ''));

        if ($ttl === null) {
            return $this->send($call, $cluster);
        }

        $key = $this->cacheKey($call, $cluster);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $this->envelope($call, $cached['result']);
        }

        $response = $this->send($call, $cluster);

        if (array_key_exists('result', $response)) {
            Cache::put($key, ['result' => $response['result']], $ttl);
        }

        return $response;
    }

    /**
     * Forward a batch as one upstream request, the way the client sent it.
     *
     * Batches are never cached: they are how transaction histories are read,
     * which is a different call every time, and splitting one up to cache a
     * member would turn a single upstream request into several.
     *
     * @param  array<int, array<string, mixed>>  $calls
     * @return array<int, mixed>
     *
     * @throws \RuntimeException when no upstream answered
     */
    public function forwardBatch(array $calls, string $cluster = self::DEFAULT_CLUSTER): array
    {
        $response = $this->send(array_values($calls), $cluster);

        return array_values($response);
    }

    /**
     * Try each upstream in turn; the first real answer wins.
     *
     * A refusal is not an answer: an HTTP error, a body that is not JSON (an
     * exhausted key replies `max usage reached` in plain text) or one of the
     * codes above all mean the next upstream gets the call. Failures are
     * logged by host — never by URL, because the key lives in the query.
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     *
     * @throws \RuntimeException
     */
    private function send(array $body, string $cluster): array
    {
        $upstreams = $this->upstreams($cluster);
        $timeout = max(1, (int) config('solana.rpc.timeout', 20));
        $failures = [];

        // Falling through a list must not mean waiting through it: the whole
        // attempt gets twice one upstream's patience, however many are listed,
        // because a page waiting a minute and a half has already given up.
        $deadline = microtime(true) + ($timeout * 2);

        foreach ($upstreams as $url) {
            $remaining = (int) ceil($deadline - microtime(true));

            if ($remaining < 1) {
                $failures[] = 'gave up before '.$this->host($url);

                break;
            }

            try {
                $response = Http::timeout(min($timeout, $remaining))
                    ->withBody(json_encode($body), 'application/json')
                    ->post($url);
            } catch (\Throwable $e) {
                $failures[] = $this->host($url).': '.class_basename($e);

                continue;
            }

            $decoded = json_decode($response->body(), true);

            if (! $response->successful() || ! is_array($decoded)) {
                $failures[] = $this->host($url).': HTTP '.$response->status();

                continue;
            }

            if ($this->isRefusal($decoded)) {
                $failures[] = $this->host($url).': refused';

                continue;
            }

            return $decoded;
        }

        Log::warning('Solana RPC proxy: no upstream answered', [
            'cluster' => $cluster,
            'failures' => $failures,
        ]);

        throw new \RuntimeException('No Solana upstream answered');
    }

    /**
     * Does this look like a refusal rather than a result? Checked on single
     * calls only — one member of a batch erroring is that member's answer.
     *
     * @param  array<mixed>  $decoded
     */
    private function isRefusal(array $decoded): bool
    {
        $code = $decoded['error']['code'] ?? null;

        return is_int($code) && in_array($code, self::REFUSAL_CODES, true);
    }

    /** Seconds this method's answer may be reused, or null when it may not. */
    private function cacheSeconds(string $method): ?int
    {
        $ttl = (int) config('solana.rpc.cache.'.$method, 0);

        return $ttl > 0 ? $ttl : null;
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private function cacheKey(array $call, string $cluster): string
    {
        return 'solana.rpc.'.$cluster.'.'.($call['method'] ?? '').'.'
            .sha1(json_encode($call['params'] ?? []) ?: '');
    }

    /**
     * A cached result, wrapped in the envelope this caller asked for — same
     * `id`, so a client matching answers to requests still matches them.
     *
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function envelope(array $call, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $call['id'] ?? null,
            'result' => $result,
        ];
    }

    private function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: 'unknown');
    }
}
