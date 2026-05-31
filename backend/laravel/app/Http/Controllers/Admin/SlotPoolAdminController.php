<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlotPool;
use App\Models\SlotPoolToken;
use App\Services\Slots\SlotPoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlotPoolAdminController extends Controller
{
    public function __construct(private readonly SlotPoolService $pools) {}

    public function index(SlotPool $pool): JsonResponse
    {
        return response()->json([
            'pool' => $pool,
            'tokens' => $pool->tokens()->orderBy('enabled', 'desc')->orderBy('symbol')->get(),
        ]);
    }

    public function addToken(Request $request, SlotPool $pool): JsonResponse
    {
        $data = $request->validate([
            'mint' => ['required', 'string', 'min:32', 'max:44'],
            'auto_enable' => ['nullable', 'boolean'],
        ]);

        $token = $this->pools->whitelistToken($pool, $data['mint'], (bool) ($data['auto_enable'] ?? false));

        return response()->json($token, 201);
    }

    public function updateToken(Request $request, SlotPool $pool, SlotPoolToken $token): JsonResponse
    {
        abort_unless($token->slot_pool_id === $pool->id, 404);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'min_bet' => ['nullable', 'string'],
            'max_bet' => ['nullable', 'string'],
            'weight_override' => ['nullable', 'integer', 'min:0'],
        ]);

        $token->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($token->fresh());
    }

    public function removeToken(SlotPool $pool, SlotPoolToken $token): JsonResponse
    {
        abort_unless($token->slot_pool_id === $pool->id, 404);

        $token->delete();

        return response()->json(['ok' => true]);
    }

    public function syncBalances(SlotPool $pool): JsonResponse
    {
        $result = $this->pools->syncBalances($pool);

        return response()->json(['balances' => $result]);
    }
}
