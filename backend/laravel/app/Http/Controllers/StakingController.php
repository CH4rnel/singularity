<?php

namespace App\Http\Controllers;

use App\Services\SolanaStakingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StakingController extends Controller
{
    public function __invoke(Request $request, SolanaStakingService $staking): Response
    {
        return Inertia::render('Staking', [
            'evm' => [
                'masterchef' => '0xd540DEa828567160FFDe5e792ca359aDD1f6B03D',
                'wcyber' => '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9',
                'ash' => '0x992Fca0a89DD95afb17751f6CC233Adb9B089df5',
            ],
            'solana' => $staking->publicConfig(),
            'position' => $request->user() ? $staking->snapshot($request->user()) : null,
        ]);
    }
}
