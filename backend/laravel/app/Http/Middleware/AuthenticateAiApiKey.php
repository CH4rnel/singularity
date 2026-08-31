<?php

namespace App\Http\Middleware;

use App\Exceptions\AiApiException;
use App\Services\Ai\AiHolderGate;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiUsageMeter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to /api/ai/v1: a key, the holding behind it, and the quota on it.
 *
 * In that order, and all three before a provider is touched. The key says
 * which address is calling; the gate says whether that address still holds
 * what the API asks of it; the meter says whether this key has any calls left
 * today. Only then does the request cost anything upstream.
 *
 * The three checks are also the three ways in: 401 means the key is wrong,
 * 403 means the holding is gone, 429 means the quota is spent — a caller can
 * act on each without guessing.
 */
class AuthenticateAiApiKey
{
    public function __construct(
        private AiKeyService $keys,
        private AiHolderGate $gate,
        private AiUsageMeter $meter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The other door. A caller who paid for this call was already let in by
        // X402Paywall, holds no key and answers to no holding — that is the
        // entire claim of x402, and asking them for a credential here would
        // take it back.
        if ($request->attributes->has('x402_payment')) {
            return $next($request);
        }

        $key = $this->keys->resolve($this->bearer($request));

        if ($key === null) {
            throw AiApiException::unauthorized(
                'Missing or invalid API key. Send it as `Authorization: Bearer '.AiKeyService::PREFIX.'…`.',
            );
        }

        // A service key answers to the quota but not to the chain: it belongs
        // to one of Cyberia's own daemons, which holds nothing and cannot.
        $status = $key->gate_exempt ? null : $this->gate->assert($key->address);

        $this->meter->enforce($key);
        $this->keys->touch($key);

        $request->attributes->set('ai_api_key', $key);
        $request->attributes->set('ai_gate_status', $status);

        return $next($request);
    }

    /**
     * The presented credential.
     *
     * `Authorization: Bearer` is what an OpenAI client sends without being
     * told to. `X-Api-Key` is accepted too, because some HTTP tooling reserves
     * the Authorization header for its own use.
     */
    private function bearer(Request $request): string
    {
        return (string) ($request->bearerToken() ?: $request->header('X-Api-Key', ''));
    }
}
