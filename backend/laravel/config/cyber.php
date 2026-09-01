<?php

/*
|--------------------------------------------------------------------------
| $CYBER — what the coin does, stated once
|--------------------------------------------------------------------------
|
| The /cyber page answers "why does this chain need its coin", and the only
| way that page is worth anything is if every claim on it is checkable. So
| the claims live here, next to the address that proves them, and the page
| renders nothing it cannot point at.
|
| Addresses come from crypto/hardhat/deployments/*.json. When a contract is
| redeployed this file is the edit — never a migration, never a string in a
| Vue file.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The chain itself
    |--------------------------------------------------------------------------
    |
    | Facts about the network the page states outright, so a reader can add it
    | to a wallet without hunting for the numbers.
    |
    */

    'chain' => [
        'name' => 'Cyberia',
        'id' => 49406,
        'id_hex' => '0xC0FE',
        'symbol' => 'CYBER',
        'decimals' => 18,
        'block_time' => '~1s',
        'consensus' => 'IBFT PoA (polygon-edge)',
        // This value is rendered into a public page. CYBERIA_RPC_URL may point
        // at the container-only node used by server-side jobs in production.
        'rpc' => 'https://rpc.cyberia.church',
        'explorer' => rtrim((string) env('CYBERIA_EXPLORER_URL', 'https://explorer.cyberia.church'), '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contracts the page cites
    |--------------------------------------------------------------------------
    |
    | Each entry is a claim with a receipt. `wcyber` is the ERC-20 wrapper the
    | coin trades as; `cyber_sol` is a *different* token (Solana-issued, bridged
    | in) that shares the name and is the single most common confusion about
    | this ecosystem, which is why the page separates them before anything else.
    |
    */

    'contracts' => [
        'wcyber' => '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9',
        'cyber_sol' => '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA',
        'cyber_sol_mint' => 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump',
        'bridge' => '0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90',
        'gas_station' => '0xA2134C165737Eff0775b163b73377E394004E7b2',
        'launchpad' => '0x8034E6C09E0cEA00B5D692ADfD1A136fab339165',
        'lending_comptroller' => '0xe66aa9842dc74F1c10ede19cA20Ece6E08F1CC88',
        // The lending market over WCYBER — i.e. over the coin. The market the
        // deployment file calls "CYBER" is over the bridged Solana token and is
        // deliberately not cited here.
        'lending_wcyber_market' => '0x5ea7cFE8971cCbD521F0f9db6Da7E019dBe2Ab8d',
        // The QuickSwap-fork router every swap and every liquidity change on
        // this chain goes through, and the contract that turns bridged
        // CYBER.sol into the native coin. Both are here because achievement
        // detection reads the address's own transaction history and has to
        // know which contracts mean which action.
        'dex_router' => '0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62',
        'cyber_sol_swap' => '0x69b1614B088F5670E49bcC6fE33F28F2544F7415',
    ],

    /*
    |--------------------------------------------------------------------------
    | Launchpad terms
    |--------------------------------------------------------------------------
    |
    | LaunchpadNative.launch() takes native CYBER, pairs it against 100% of the
    | new token's supply and sends the LP tokens to the burn address. The LP is
    | unrecoverable — the CYBER stays in the pool as its reserve.
    |
    */

    'launchpad' => [
        'min_cyber' => 10,
        'lp_burned' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | The live figures come from the local pool snapshot, so this is short.
    |
    */

    'cache_ttl' => (int) env('CYBER_PAGE_CACHE_TTL', 300),

];
