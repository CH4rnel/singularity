<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FujiFaucetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Test CYBER on Cyberia Fuji.
 *
 * Unsigned, like the gas station: a drip can only ever arrive at the address
 * that was named, and a signature would prove possession of a key anyone can
 * generate for free. The cooldown, the quotas and the tank are what limit it.
 * See FujiFaucetService.
 */
class FujiFaucetController extends Controller
{
    /** Refusals that are the caller's situation rather than a fault. */
    private const POLICY_REASONS = [
        FujiFaucetService::HAS_GAS,
        FujiFaucetService::COOLING_DOWN,
        FujiFaucetService::QUOTA,
        FujiFaucetService::DAILY_CAP,
        FujiFaucetService::EMPTY_TANK,
    ];

    public function __construct(private FujiFaucetService $faucet) {}

    /** The chain's live fee, the faucet, and where this address stands with it. */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['sometimes', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        return response()->json(
            $this->faucet->status($data['address'] ?? null, $request->ip()),
        );
    }

    /** Send this address one drip, if it may have one. */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $result = $this->faucet->claim($data['address'], $request->ip());

        if ($result['ok']) {
            return response()->json([
                'status' => 'sent',
                'txHash' => $result['txHash'] ?? null,
                'amount' => $result['amount'] ?? '0',
                'confirmed' => $result['confirmed'] ?? false,
            ]);
        }

        // A code, not a sentence: the page holds the sentence in each language.
        return response()->json(
            ['reason' => $result['reason']],
            $this->statusFor($result['reason']),
        );
    }

    private function statusFor(string $reason): int
    {
        if ($reason === FujiFaucetService::DISABLED) {
            return 404;
        }

        if (in_array($reason, self::POLICY_REASONS, true)) {
            return 422;
        }

        return 503;
    }
}
