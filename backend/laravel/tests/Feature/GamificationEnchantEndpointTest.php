<?php

use App\Models\User;
use App\Models\XpEnchantment;
use App\Services\GamificationService;

/**
 * Buying, over HTTP.
 *
 * A permanent purchase reached by a form has to survive the two things forms
 * do: being submitted twice, and being submitted for something the person
 * cannot have.
 */
function buyer(int $xp = 50_000): User
{
    $user = User::factory()->create();
    app(GamificationService::class)->award($user, 'swap', 'swap:'.uniqid('', true), $xp);

    return $user;
}

it('buys one', function () {
    $user = buyer();

    $this->actingAs($user)->post('/profile/enchant', ['key' => 'nocarrier'])
        ->assertRedirect()
        ->assertSessionHas('status', 'enchant-bought:nocarrier');

    expect(XpEnchantment::where('user_id', $user->id)->count())->toBe(1);
});

it('does not charge a second time for a resubmitted form', function () {
    $user = buyer();

    $this->actingAs($user)->post('/profile/enchant', ['key' => 'nocarrier']);
    $this->actingAs($user)->post('/profile/enchant', ['key' => 'nocarrier'])
        ->assertSessionHas('status', 'enchant-owned')
        ->assertSessionHasNoErrors();

    expect(XpEnchantment::where('user_id', $user->id)->sum('cost'))->toBe(5000);
});

it('names the refusal rather than failing vaguely', function () {
    // Level 6: the balance is short too, and the level is what is reported,
    // because saving does not fix it.
    $user = buyer(1600);

    $this->actingAs($user)->post('/profile/enchant', ['key' => 'nocarrier'])
        ->assertSessionHasErrors(['enchant' => 'level']);

    expect(XpEnchantment::where('user_id', $user->id)->count())->toBe(0);
});

it('says when the balance is the thing that is short', function () {
    $this->actingAs(buyer(4500))->post('/profile/enchant', ['key' => 'nocarrier'])
        ->assertSessionHasErrors(['enchant' => 'xp']);
});

it('refuses an unlock nobody offers', function () {
    $this->actingAs(buyer())->post('/profile/enchant', ['key' => 'sharpness_v'])
        ->assertSessionHasErrors(['enchant' => 'unknown']);
});

it('is closed to a guest', function () {
    $this->post('/profile/enchant', ['key' => 'nocarrier'])->assertRedirect('/login');
});
