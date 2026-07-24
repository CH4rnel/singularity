<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SolanaStakingTransaction;
use App\Services\SolanaStakingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolanaStakingController extends Controller
{
    public function __construct(private readonly SolanaStakingService $staking) {}

    public function state(Request $request): JsonResponse
    {
        return response()->json([
            'config' => $this->staking->publicConfig(),
            'position' => $this->staking->snapshot($request->user()),
        ]);
    }

    public function prepareDeposit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount_raw' => ['required', 'string', 'regex:/^[0-9]+$/', 'not_in:0'],
        ]);

        try {
            $transaction = $this->staking->prepareDeposit(
                $request->user(),
                $validated['amount_raw'],
            );
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $config = $this->staking->publicConfig();

        return response()->json([
            'deposit' => [
                'uuid' => $transaction->uuid,
                'amount_raw' => $transaction->amount_raw,
                'memo' => $this->staking->depositMemo($transaction),
                'treasury_address' => $config['treasury_address'],
                'mint' => $config['cyber_sol_mint'],
                'decimals' => $config['cyber_sol_decimals'],
                'token_program' => $config['token_program'],
                'cluster' => $config['cluster'],
                'rpc_url' => $config['rpc_url'],
            ],
        ], 201);
    }

    public function confirmDeposit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid' => ['required', 'uuid'],
            'tx_hash' => ['required', 'string', 'min:64', 'max:100', 'regex:/^[1-9A-HJ-NP-Za-km-z]+$/'],
        ]);

        try {
            $transaction = $this->staking->confirmDeposit(
                $request->user(),
                $validated['uuid'],
                $validated['tx_hash'],
            );
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->stateResponse($request, $transaction);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount_raw' => ['required', 'string', 'regex:/^[0-9]+$/', 'not_in:0'],
        ]);

        try {
            $transaction = $this->staking->withdraw(
                $request->user(),
                $validated['amount_raw'],
            );
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->stateResponse($request, $transaction);
    }

    public function claim(Request $request): JsonResponse
    {
        try {
            $transaction = $this->staking->claimRewards($request->user());
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->stateResponse($request, $transaction);
    }

    private function stateResponse(
        Request $request,
        SolanaStakingTransaction $transaction,
    ): JsonResponse {
        return response()->json([
            'transaction' => [
                'uuid' => $transaction->uuid,
                'type' => $transaction->type,
                'amount_raw' => $transaction->amount_raw,
                'tx_hash' => $transaction->tx_hash,
                'status' => $transaction->status,
                'error_message' => $transaction->error_message,
            ],
            'position' => $this->staking->snapshot($request->user()),
        ]);
    }
}
