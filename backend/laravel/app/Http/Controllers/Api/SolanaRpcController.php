<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SolanaRpcProxy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * JSON-RPC in, JSON-RPC out — the browser's endpoint for Solana.
 *
 * This is a relay, not a wallet: it holds no key, signs nothing and keeps no
 * record of who asked. It exists because Solana's public cluster refuses any
 * request carrying a browser `Origin`, and because the endpoints that do
 * answer browsers want a key in the URL — a key in a bundle being a key
 * anyone may spend. See `App\Services\SolanaRpcProxy`.
 *
 * The two things it decides are what may be asked and how often: the method
 * allowlist in `config/solana.php` (a name says nothing about a call's cost —
 * `getProgramAccounts` reads an entire program's state) and the throttle on
 * the route. A refusal here is always shaped as a JSON-RPC error, so a client
 * that speaks the protocol can read it without special-casing this host.
 */
class SolanaRpcController extends Controller
{
    public function __construct(private readonly SolanaRpcProxy $proxy) {}

    public function __invoke(Request $request, ?string $cluster = null): JsonResponse
    {
        $cluster = $cluster ?: SolanaRpcProxy::DEFAULT_CLUSTER;

        if (! $this->proxy->enabled()) {
            return $this->error(503, -32603, 'The Solana relay is switched off on this host.');
        }

        if (! $this->proxy->knows($cluster)) {
            return $this->error(404, -32601, 'Unknown Solana cluster.');
        }

        if ($denied = $this->guardOrigin($request)) {
            return $denied;
        }

        $body = $request->getContent();
        $maxBytes = (int) config('solana.rpc.max_bytes', 131072);

        if (strlen($body) > $maxBytes) {
            return $this->error(413, -32600, 'Request larger than '.$maxBytes.' bytes.');
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || $payload === []) {
            return $this->error(400, -32700, 'Expected a JSON-RPC request.');
        }

        // A batch is a list; a single call is a map with a `method`. The wallet
        // reads its history as a batch, so both are first-class here.
        return array_is_list($payload)
            ? $this->batch($payload, $cluster)
            : $this->single($payload, $cluster);
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private function single(array $call, string $cluster): JsonResponse
    {
        if ($refusal = $this->refuseMethod($call)) {
            return $refusal;
        }

        try {
            return response()->json($this->proxy->forward($call, $cluster));
        } catch (\Throwable) {
            return $this->error(502, -32603, 'Solana is unreachable from here right now.', $call['id'] ?? null);
        }
    }

    /**
     * @param  array<int, mixed>  $calls
     */
    private function batch(array $calls, string $cluster): JsonResponse
    {
        $max = (int) config('solana.rpc.max_batch', 25);

        if (count($calls) > $max) {
            return $this->error(400, -32600, 'A batch may hold at most '.$max.' calls.');
        }

        foreach ($calls as $call) {
            if (! is_array($call)) {
                return $this->error(400, -32600, 'Every batch member must be a JSON-RPC request.');
            }

            if ($refusal = $this->refuseMethod($call)) {
                return $refusal;
            }
        }

        try {
            /** @var array<int, array<string, mixed>> $calls */
            return response()->json($this->proxy->forwardBatch($calls, $cluster));
        } catch (\Throwable) {
            return $this->error(502, -32603, 'Solana is unreachable from here right now.');
        }
    }

    /**
     * The one policy question asked of every call: is this method on the list?
     *
     * @param  array<string, mixed>  $call
     */
    private function refuseMethod(array $call): ?JsonResponse
    {
        $method = $call['method'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->error(400, -32600, 'Missing JSON-RPC method.', $call['id'] ?? null);
        }

        if (! $this->proxy->allows($method)) {
            return $this->error(400, -32601, 'Method '.$method.' is not relayed by this host.', $call['id'] ?? null);
        }

        return null;
    }

    /**
     * Browser origins, when the host names any.
     *
     * A request with no `Origin` is a script or another server, not the abuse
     * this guards against, so it passes. The list is empty by default: while
     * the public cluster is what answers, the per-IP throttle is limit enough,
     * and an over-tight list here breaks pages served from a sibling domain.
     */
    private function guardOrigin(Request $request): ?JsonResponse
    {
        $allowed = (array) config('solana.rpc.origins', []);
        $origin = $request->headers->get('Origin');

        if ($allowed === [] || $origin === null || $origin === '') {
            return null;
        }

        $host = (string) (parse_url($origin, PHP_URL_HOST) ?: '');

        foreach ($allowed as $pattern) {
            $pattern = (string) $pattern;
            $patternHost = (string) (parse_url($pattern, PHP_URL_HOST) ?: $pattern);

            if ($host !== '' && Str::is($patternHost, $host)) {
                return null;
            }
        }

        return $this->error(403, -32600, 'This origin may not use the Solana relay.');
    }

    private function error(int $status, int $code, string $message, mixed $id = null): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
