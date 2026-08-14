<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlotSpin;
use App\Services\Slots\SlotMachineService;
use App\Services\Slots\SlotPoolService;
use App\Services\SolanaRpcProxy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlotsController extends Controller
{
    public function __construct(
        private readonly SlotPoolService $pools,
        private readonly SlotMachineService $machine,
        private readonly SolanaRpcProxy $solanaRpc,
    ) {}

    public function pool(): JsonResponse
    {
        $pool = $this->pools->activePool();

        if (! $pool) {
            return response()->json(['error' => 'No active pool'], 503);
        }

        return response()->json([
            'pool_id' => $pool->id,
            'hot_wallet' => $pool->hot_wallet_address,
            'burn_bps' => $pool->burn_bps,
            'house_edge_bps' => $pool->house_edge_bps,
            'jackpot_bps' => $pool->jackpot_threshold_bps,
            'cluster' => config('services.slots.cluster'),
            // The relay for this cluster rather than the cluster itself: a
            // browser asking Solana directly is answered 403 whichever cluster
            // it asks. Falls back to the configured URL when the relay is off.
            'rpc_url' => $this->solanaRpc->browserEndpoint(
                (string) config('services.slots.cluster', 'devnet'),
                (string) config('services.slots.rpc_url'),
            ),
            'tokens' => $this->pools->snapshot($pool),
        ]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wallet_address' => ['required', 'string', 'min:32', 'max:44'],
            'bet_mint' => ['required', 'string', 'min:32', 'max:44'],
            'bet_amount' => ['required', 'string', 'regex:/^[0-9]+$/'],
            'client_seed' => ['required', 'string', 'min:4', 'max:128'],
        ]);

        $pool = $this->pools->activePool();
        if (! $pool || ! $pool->isActive()) {
            return response()->json(['error' => 'Pool not active'], 503);
        }

        try {
            $spin = $this->machine->prepare(
                pool: $pool,
                walletAddress: $data['wallet_address'],
                betMint: $data['bet_mint'],
                betAmountRaw: $data['bet_amount'],
                clientSeed: $data['client_seed'],
            );
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'spin_id' => $spin->id,
            'server_seed_hash' => $spin->server_seed_hash,
            'nonce' => $spin->nonce,
            'deposit_address' => $spin->deposit_address,
            'expected_amount' => $spin->bet_amount,
            'expires_at' => $spin->expires_at?->toIso8601String(),
        ], 201);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'spin_id' => ['required', 'integer'],
            'deposit_tx_hash' => ['required', 'string', 'min:32'],
        ]);

        $spin = SlotSpin::find($data['spin_id']);
        if (! $spin) {
            return response()->json(['error' => 'Spin not found'], 404);
        }

        if (SlotSpin::where('deposit_tx_hash', $data['deposit_tx_hash'])->where('id', '!=', $spin->id)->exists()) {
            return response()->json(['error' => 'Transaction already used'], 409);
        }

        try {
            $spin = $this->machine->confirm($spin, $data['deposit_tx_hash']);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Settle failed: '.$e->getMessage()], 500);
        }

        return response()->json([
            'spin_id' => $spin->id,
            'outcome_type' => $spin->outcome_type,
            'reels' => $spin->reels,
            'prize_payload' => $spin->prize_payload,
            'payout_tx_hash' => $spin->payout_tx_hash,
            'burn_amount' => $spin->burn_amount,
            'server_seed' => $spin->server_seed,
            'server_seed_hash' => $spin->server_seed_hash,
            'client_seed' => $spin->client_seed,
            'nonce' => $spin->nonce,
        ]);
    }
}
