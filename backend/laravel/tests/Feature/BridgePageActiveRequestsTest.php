<?php

use App\Models\BridgeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::fake(['*' => Http::response(['pairs' => []])]);
    config()->set('bridge.chains.yenten.deposit_ttl_minutes', 60);
});

function bridgeRequestForUser(User $user, array $overrides = []): BridgeRequest
{
    $createdAt = $overrides['created_at'] ?? null;
    $updatedAt = $overrides['updated_at'] ?? $createdAt;

    unset($overrides['created_at'], $overrides['updated_at']);

    $request = BridgeRequest::create(array_merge([
        'user_id' => $user->id,
        'direction' => 'yenten_to_evm',
        'token' => 'YTN',
        'source_chain' => 'yenten',
        'source_tx_hash' => null,
        'source_nonce' => 0,
        'sender_address' => null,
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'deposit_address' => 'YDepositAddress1111111111111111111',
        'amount' => '0',
        'fee_amount' => '0',
        'status' => 'awaiting_deposit',
    ], $overrides));

    if ($createdAt) {
        $request->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ])->save();
    }

    return $request->refresh();
}

test('bridge page exposes active non-expired requests for the signed-in user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $awaiting = bridgeRequestForUser($user, [
        'deposit_address' => 'YActiveDeposit11111111111111111111',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $pending = bridgeRequestForUser($user, [
        'direction' => 'evm_to_yenten',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('ab', 32),
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'deposit_address' => null,
        'amount' => '2',
        'status' => 'pending',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    bridgeRequestForUser($user, [
        'deposit_address' => 'YExpiredDeposit111111111111111111',
        'created_at' => now()->subMinutes(90),
        'updated_at' => now()->subMinutes(90),
    ]);

    bridgeRequestForUser($user, ['status' => 'completed']);
    bridgeRequestForUser($other, ['deposit_address' => 'YOtherDeposit11111111111111111111']);

    $this->actingAs($user)
        ->get('/bridge')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bridge')
            ->has('bridgeActiveRequests', 2)
            ->where('bridgeActiveRequests.0.id', $pending->id)
            ->where('bridgeActiveRequests.1.id', $awaiting->id)
            ->where('bridgeActiveRequests.1.deposit_address', 'YActiveDeposit11111111111111111111')
            ->where('bridgeActiveRequests.1.expires_at', $awaiting->created_at->copy()->addHour()->toIso8601String()));
});
