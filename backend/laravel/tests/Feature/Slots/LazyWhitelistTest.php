<?php

use App\Models\SlotPool;
use App\Models\SlotPoolToken;
use Illuminate\Support\Facades\Http;

const LAZY_HOT_WALLET = 'SLOT11111111111111111111111111111111111111';
const LAZY_USER_WALLET = 'UsErWaLLEt1111111111111111111111111111111111';
const LAZY_NEW_MINT = 'NEWNEWNEWNEWNEWNEWNEWNEWNEWNEWNEWNEWNEWNEW';

beforeEach(function () {
    config()->set('services.slots.hot_wallet_address', LAZY_HOT_WALLET);
    config()->set('services.slots.hot_wallet_keypair_path', '/tmp/fake.json');
    config()->set('services.slots.rpc_url', 'https://slot.rpc.test');
    config()->set('services.slots.pumpfun_api_base', 'https://frontend-api-v3.pump.fun');
    config()->set('services.slots.pumpfun_lazy_enabled', true);
    config()->set('services.slots.pumpfun_auto_enable', true);

    SlotPool::create([
        'name' => 'test',
        'status' => 'active',
        'hot_wallet_address' => LAZY_HOT_WALLET,
        'burn_bps' => 200,
        'house_edge_bps' => 400,
        'jackpot_threshold_bps' => 0,
        'max_single_win_bps' => 2000,
        'jackpot_basket_bps' => 2500,
        'jackpot_basket_size' => 5,
    ]);
});

function fakeHttpForLazy(bool $pumpfunKnowsMint, bool $heliusHasFreeze = false): void
{
    Http::fake(function ($request) use ($pumpfunKnowsMint, $heliusHasFreeze) {
        $url = $request->url();

        if (str_contains($url, 'frontend-api-v3.pump.fun/coins/')) {
            if ($pumpfunKnowsMint) {
                return Http::response([
                    'mint' => LAZY_NEW_MINT,
                    'symbol' => 'NEW',
                    'name' => 'New Coin',
                    'image_uri' => 'https://cf-ipfs.com/ipfs/Qm',
                    'usd_market_cap' => 12345.67,
                ]);
            }

            return Http::response(['error' => 'not found'], 404);
        }

        // Helius DAS getAsset
        return Http::response([
            'result' => [
                'content' => [
                    'metadata' => ['symbol' => 'NEW', 'name' => 'New Coin'],
                    'links' => ['image' => 'https://cf-ipfs.com/ipfs/Qm'],
                ],
                'token_info' => [
                    'symbol' => 'NEW',
                    'decimals' => 6,
                    'token_program' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
                    'freeze_authority' => $heliusHasFreeze ? 'someFreezeAuth' : null,
                ],
            ],
        ]);
    });
}

it('lazy-whitelists a pump.fun mint on first bet', function () {
    fakeHttpForLazy(pumpfunKnowsMint: true);

    $response = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => LAZY_USER_WALLET,
        'bet_mint' => LAZY_NEW_MINT,
        'bet_amount' => '1000000',
        'client_seed' => 'lazytest',
    ]);

    $response->assertCreated();

    $token = SlotPoolToken::where('mint', LAZY_NEW_MINT)->first();
    expect($token)->not->toBeNull();
    expect($token->enabled)->toBeTrue();
    expect($token->source)->toBe(SlotPoolToken::SOURCE_PUMPFUN_LAZY);
    expect($token->pumpfun_last_seen_at)->not->toBeNull();
});

it('rejects a mint pump.fun does not recognize', function () {
    fakeHttpForLazy(pumpfunKnowsMint: false);

    $response = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => LAZY_USER_WALLET,
        'bet_mint' => LAZY_NEW_MINT,
        'bet_amount' => '1000000',
        'client_seed' => 'lazytest',
    ]);

    $response->assertStatus(422);
    expect(SlotPoolToken::where('mint', LAZY_NEW_MINT)->exists())->toBeFalse();
});

it('does not enable mints with freeze authority even if pump.fun verifies them', function () {
    fakeHttpForLazy(pumpfunKnowsMint: true, heliusHasFreeze: true);

    $response = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => LAZY_USER_WALLET,
        'bet_mint' => LAZY_NEW_MINT,
        'bet_amount' => '1000000',
        'client_seed' => 'lazytest',
    ]);

    $response->assertStatus(422);

    // Row was created but disabled.
    $token = SlotPoolToken::where('mint', LAZY_NEW_MINT)->first();
    expect($token)->not->toBeNull();
    expect($token->enabled)->toBeFalse();
});

it('respects the SLOT_PUMPFUN_LAZY_ENABLED flag', function () {
    config()->set('services.slots.pumpfun_lazy_enabled', false);
    fakeHttpForLazy(pumpfunKnowsMint: true);

    $response = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => LAZY_USER_WALLET,
        'bet_mint' => LAZY_NEW_MINT,
        'bet_amount' => '1000000',
        'client_seed' => 'lazytest',
    ]);

    $response->assertStatus(422);
    expect(SlotPoolToken::where('mint', LAZY_NEW_MINT)->exists())->toBeFalse();
});
