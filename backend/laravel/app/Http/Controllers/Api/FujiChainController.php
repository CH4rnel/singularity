<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FujiChainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The wallet's chain panel for Cyberia Fuji: public, read-only.
 *
 * Every figure here is on chain for anyone to read, so nothing is hidden. The
 * roles of a named address are what decide whether the wallet draws the
 * controls, and the chain enforces the same roles when the transaction lands.
 */
class FujiChainController extends Controller
{
    public function __construct(private FujiChainService $chain) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['sometimes', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        return response()->json($this->chain->status($data['address'] ?? null));
    }
}
