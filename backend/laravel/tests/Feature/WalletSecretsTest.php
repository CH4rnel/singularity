<?php

use App\Models\User;
use App\Models\XpEntry;
use Illuminate\Support\Facades\Cache;

/**
 * The terminal on layer 03.
 *
 * What matters is that a find pays once, that typing it again is "already
 * yours" rather than "wrong", and that the answers never travel to the
 * browser — the catalogue is the hint and, once found, the title.
 */
beforeEach(function () {
    Cache::flush();
});

it('pays a secret once and calls the repeat already found', function () {
    $user = User::factory()->create();
    $xp = (int) collect(config('gamification.secrets'))->firstWhere('key', 'present')['xp'];

    $first = $this->actingAs($user)
        ->postJson('/api/wallet/daily/secret', ['code' => 'Present day, present time!'])
        ->assertOk()
        ->assertJsonPath('claim.found', true)
        ->assertJsonPath('claim.fresh', true)
        ->assertJsonPath('claim.secret.key', 'present');

    expect($first->json('claim.granted'))->toBeGreaterThanOrEqual($xp);

    $this->actingAs($user)
        ->postJson('/api/wallet/daily/secret', ['code' => 'present day present time'])
        ->assertOk()
        ->assertJsonPath('claim.found', true)
        ->assertJsonPath('claim.fresh', false)
        ->assertJsonPath('claim.granted', 0);

    expect(XpEntry::query()->where('user_id', $user->id)->where('source', 'secret')->count())->toBe(1);
});

it('answers a wrong phrase without paying anything', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/wallet/daily/secret', ['code' => 'hunter2'])
        ->assertOk()
        ->assertJsonPath('claim.found', false)
        ->assertJsonPath('claim.granted', 0);

    expect(XpEntry::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('advances the weekly digging quest only on a fresh find', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/wallet/daily/secret', ['code' => 'layer:02']);
    $repeat = $this->actingAs($user)->postJson('/api/wallet/daily/secret', ['code' => 'layer:02']);

    $quest = collect($repeat->json('quests'))->firstWhere('key', 'weekly_digger');

    expect($quest['completed'])->toBeTrue()
        ->and($quest['progress'])->toBe(1);
});

it('refuses a phrase from a wallet with no account', function () {
    $this->postJson('/api/wallet/daily/secret', ['code' => 'navi'])->assertStatus(401);
});

it('never sends an answer and withholds titles until found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/wallet/daily/secret', ['code' => 'navi']);

    $secrets = collect($this->actingAs($user)->getJson('/api/wallet/daily')->json('secrets'));

    expect($secrets)->toHaveCount(count(config('gamification.secrets')))
        ->and($secrets->every(fn (array $secret) => ! array_key_exists('hash', $secret)))->toBeTrue()
        ->and($secrets->firstWhere('key', 'navi')['found'])->toBeTrue()
        ->and($secrets->firstWhere('key', 'navi')['title'])->not->toBeNull()
        ->and($secrets->firstWhere('key', 'console')['found'])->toBeFalse()
        ->and($secrets->firstWhere('key', 'console')['title'])->toBeNull();
});
