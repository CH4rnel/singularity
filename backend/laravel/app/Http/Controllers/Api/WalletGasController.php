<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GasSponsorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sponsored fees for the wallet, on Cyberia.
 *
 * Public and unsigned, and that is not an oversight. A drip goes *to* the
 * address named in the request and can only ever arrive there, so a signature
 * would prove possession of a key that anyone can generate for free — it would
 * cost a real user a tap and cost an attacker nothing. What actually limits
 * this is the address itself: what it owns, when it was last sponsored, and
 * what the station will spend in a day. See GasSponsorService.
 */
class WalletGasController extends Controller
{
    /** Refusals that are the caller's situation rather than a fault. */
    private const POLICY_REASONS = [
        GasSponsorService::HAS_GAS,
        GasSponsorService::HOLDS_NOTHING,
        GasSponsorService::COOLING_DOWN,
        GasSponsorService::QUOTA,
        GasSponsorService::DAILY_CAP,
        GasSponsorService::EMPTY_TANK,
        GasSponsorService::PAUSED,
    ];

    public function __construct(private GasSponsorService $sponsor) {}

    /**
     * What the station is, and where this address stands with it.
     *
     * The wallet asks before it offers the button, so that "fees are sponsored
     * here" is never printed by a page that has not checked.
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['sometimes', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        return response()->json(
            $this->sponsor->status($data['address'] ?? null, $request->ip()),
        );
    }

    /** Pay this address's fees, if it qualifies. */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $result = $this->sponsor->sponsor($data['address'], $request->ip());

        if ($result['ok']) {
            return response()->json([
                'status' => 'sent',
                'txHash' => $result['txHash'] ?? null,
                'amount' => $result['amount'] ?? '0',
            ]);
        }

        // The reason is a code, not a sentence: the wallet has the sentence in
        // three languages and the server has no business choosing between them.
        return response()->json(
            ['reason' => $result['reason']],
            $this->statusFor($result['reason']),
        );
    }

    private function statusFor(string $reason): int
    {
        if ($reason === GasSponsorService::DISABLED) {
            return 404;
        }

        if (in_array($reason, self::POLICY_REASONS, true)) {
            return 422;
        }

        // Anything left is this side failing to read a chain or an index, and
        // a gate that cannot read fails closed rather than open.
        return 503;
    }
}
