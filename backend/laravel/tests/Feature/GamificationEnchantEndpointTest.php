<?php

use App\Models\AiApiKey;
use App\Models\User;
use App\Models\XpEnchantment;
use App\Services\GamificationService;
use Illuminate\Support\Facades\DB;

/**
 * Buying, over HTTP.
 *
 * A permanent purchase reached by a form has to survive the two things forms
 * do: being submitted twice, and being submitted for something the person
 * cannot have.
 */
function buyer(int $xp = 5000): User
{
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('a', 40)]);

    app(GamificationService::class)->award($user, 'swap', 'swap:'.uniqid('', true), $xp);

    return $user;
}

it('buys one', function () {
    $user = buyer();

    $this->actingAs($user)->post('/profile/enchant', ['key' => 'route_i'])
        ->assertRedirect()
        ->assertSessionHas('status', 'enchant-bought:route_i');

    expect(XpEnchantment::where('user_id', $user->id)->count())->toBe(1);
});

it('does not charge a second time for a resubmitted form', function () {
    $user = buyer();

    $this->actingAs($user)->post('/profile/enchant', ['key' => 'route_i']);
    $this->actingAs($user)->post('/profile/enchant', ['key' => 'route_i'])
        ->assertSessionHas('status', 'enchant-owned')
        ->assertSessionHasNoErrors();

    expect(XpEnchantment::where('user_id', $user->id)->sum('cost'))->toBe(400);
});

it('names the refusal rather than failing vaguely', function () {
    $user = buyer(300);

    $this->actingAs($user)->post('/profile/enchant', ['key' => 'lain_key'])
        ->assertSessionHasErrors(['enchant' => 'level']);

    expect(XpEnchantment::where('user_id', $user->id)->count())->toBe(0);
});

it('refuses an enchantment nobody offers', function () {
    $this->actingAs(buyer())->post('/profile/enchant', ['key' => 'sharpness_v'])
        ->assertSessionHasErrors(['enchant' => 'unknown']);
});

it('hands over an inference key exactly once, in the response that bought it', function () {
    $user = buyer(20_000);

    $response = $this->actingAs($user)->post('/profile/enchant', ['key' => 'lain_key']);

    $status = session('status');

    expect($status)->toStartWith('enchant-key:')
        // The plaintext token exists in this response and nowhere else; the
        // row keeps only its hash.
        ->and(AiApiKey::where('address', $user->wallet_address)->first()?->gate_exempt)->toBeTrue()
        ->and(DB::table('ai_api_keys')->where('address', $user->wallet_address)->value('token_hash'))
        ->not->toContain(substr($status, strlen('enchant-key:')));

    $response->assertRedirect();
});

it('is closed to a guest', function () {
    $this->post('/profile/enchant', ['key' => 'route_i'])->assertRedirect('/login');
});
