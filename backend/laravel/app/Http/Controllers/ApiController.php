<?php

namespace App\Http\Controllers;

use App\Models\BridgeRequest;
use App\Services\BridgeConfigService;
use App\Services\BridgeFeeService;
use App\Services\BridgeRelayerService;
use App\Services\CataasApiService;
use App\Services\CyberPriceService;
use App\Services\YesNoApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Laravel\Fortify\Features;

class ApiController extends Controller
{
    public function index(YesNoApiService $yesNo, CataasApiService $cataas, CyberPriceService $cyber, BridgeConfigService $bridgeConfig, BridgeFeeService $bridgeFee, Request $request)
    {
        $price = $cyber->get();

        $bridgeHistory = [];
        $bridgeActiveRequests = [];
        if (auth()->check()) {
            $bridgeHistory = BridgeRequest::where('user_id', auth()->id())
                ->orderByDesc('created_at')
                ->limit(20)
                ->get([
                    'id', 'direction', 'token', 'source_chain', 'source_tx_hash',
                    'sender_address', 'recipient_address', 'deposit_address',
                    'amount', 'status', 'destination_tx_hash', 'created_at',
                    'completed_at',
                ])
                ->map(fn (BridgeRequest $request) => $this->bridgeRequestPayload($request));

            $bridgeActiveRequests = BridgeRequest::where('user_id', auth()->id())
                ->whereIn('status', ['awaiting_deposit', 'pending', 'processing'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get([
                    'id', 'direction', 'token', 'source_chain',
                    'source_tx_hash', 'sender_address', 'recipient_address',
                    'deposit_address', 'amount', 'status',
                    'destination_tx_hash', 'created_at', 'completed_at',
                ])
                ->filter(function (BridgeRequest $request) {
                    if ($this->bridgeRequestExpiredForUi($request)) {
                        $request->markExpired();

                        return false;
                    }

                    return true;
                })
                ->values()
                ->map(fn (BridgeRequest $request) => $this->bridgeRequestPayload($request));
        }

        return Inertia::render('Bridge', [
            'price' => $price,
            'bridgeHistory' => $bridgeHistory,
            'bridgeActiveRequests' => $bridgeActiveRequests,
            'bridgeRelayerEvm' => app(BridgeRelayerService::class)->evmAddress(),
            // Full bridge config for the frontend: chains, routes and tokens
            // all come from config/bridge.php — adding an EVM chain is a
            // config-only change, no frontend edits.
            'bridgeChains' => $bridgeConfig->publicChains(),
            'bridgeRoutes' => $bridgeConfig->publicRoutes(),
            'bridgeTokens' => $bridgeConfig->publicTokens(),
            'bridgeDirections' => array_column($bridgeConfig->publicRoutes(), 'direction'),
            'bridgeYentenDepositAddress' => $bridgeConfig->depositAddress('yenten'),
            'bridgeFeeConfig' => [
                'flatUsd' => (float) config('bridge.fee.flat_usd', 0.1),
                'rateBps' => (int) config('bridge.fee.rate_bps', 0),
                'nativeRouteFees' => $bridgeFee->frontendNativeFees(),
            ],
            'bridgeGasDrop' => [
                'enabled' => (bool) config('bridge.gas_drop.enabled', true),
                'amount' => (string) config('bridge.gas_drop.amount_cyber', '0.01'),
            ],
            'bridgeConvert' => [
                'enabled' => (bool) config('bridge.convert.enabled', true),
                'rate' => (int) config('bridge.convert.rate', 1000),
            ],
        ]);
    }

    private function bridgeRequestExpiredForUi(BridgeRequest $request): bool
    {
        return $request->status === 'awaiting_deposit'
            && $request->source_chain === 'yenten'
            && $request->created_at->lte(now()->subMinutes($this->yentenDepositTtlMinutes()));
    }

    /**
     * @return array<string, mixed>
     */
    private function bridgeRequestPayload(BridgeRequest $request): array
    {
        $expiresAt = $request->status === 'awaiting_deposit' && $request->source_chain === 'yenten'
            ? $request->created_at->copy()->addMinutes($this->yentenDepositTtlMinutes())->toIso8601String()
            : null;

        return [
            'id' => $request->id,
            'direction' => $request->direction,
            'token' => $request->token,
            'source_chain' => $request->source_chain,
            'source_tx_hash' => $request->source_tx_hash,
            'sender_address' => $request->sender_address,
            'recipient_address' => $request->recipient_address,
            'deposit_address' => $request->deposit_address,
            'amount' => $request->amount,
            'status' => $request->status,
            'destination_tx_hash' => $request->destination_tx_hash,
            'created_at' => $request->created_at,
            'completed_at' => $request->completed_at,
            'expires_at' => $expiresAt,
        ];
    }

    private function yentenDepositTtlMinutes(): int
    {
        return max(1, (int) config('bridge.chains.yenten.deposit_ttl_minutes', 60));
    }

    // public function index(YesNoApiService $yesNo, CataasApiService $cataas, CyberPriceService $cyber)
    // {
    //     $cats = $cataas->cats();
    //     $yesOrNo = $yesNo->get();
    //     $tags = $cataas->tags();
    //     $price = $cyber->get();

    //     return Inertia::render('Welcome', [
    //         'canRegister' => Features::enabled(Features::registration()),
    //         'yesOrNo' => $yesOrNo,
    //         'price' => $price,
    //         'cataas' => [
    //             'cat' => $cataas->cat(),
    //             'catSays' => $cataas->catSays($yesOrNo->answer),
    //             'catById' => $cataas->catById(Arr::random($cats)->id),
    //             'catByIdSays' => $cataas->catByIdSays(Arr::random($cats)->id, $yesOrNo->answer),
    //             'catByTag' => $cataas->catByTag(Arr::random($tags)),
    //             'catByTagSays' => $cataas->catByTagSays(Arr::random($tags), $yesOrNo->answer),
    //             'cats' => $cats,
    //             'count' => $cataas->count(),
    //             'tags' => $tags,
    //         ],
    //     ]);
    // }
}
