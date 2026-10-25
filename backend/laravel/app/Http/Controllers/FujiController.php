<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * /fuji — Cyberia's new chain, the Avalanche L1 on Fuji, in one page.
 *
 * Adds the network to MetaMask in one click, lists its parameters for any
 * other wallet, and hands out test CYBER. The chain's parameters come from
 * the bridge config so this page and the bridge cannot disagree; the live
 * parts (fee, tank, eligibility) are asked of /api/fuji/faucet by the page.
 */
class FujiController extends Controller
{
    public function __invoke(): Response
    {
        $chain = (array) config('bridge.chains.cyberia_fuji');

        return Inertia::render('Fuji', [
            'network' => [
                'chainId' => (int) ($chain['evm_chain_id'] ?? 49407),
                'name' => 'Cyberia Fuji',
                'rpcUrl' => (string) ($chain['public_rpc_url'] ?? 'https://cyberia.church/api/fuji/rpc'),
                'currency' => (array) ($chain['native_currency'] ?? ['name' => 'Cyber', 'symbol' => 'CYBER', 'decimals' => 18]),
                'explorerTx' => $chain['explorer_tx'] ?? null,
            ],
            'treasury' => (string) config('fuji.treasury'),
        ]);
    }
}
