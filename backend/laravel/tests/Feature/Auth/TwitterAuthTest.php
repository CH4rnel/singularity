<?php

use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;

function fakeTwitterCallback(string $id, ?string $nickname = 'lain', ?string $name = 'Lain', ?string $email = null): void
{
    $oauthUser = (new OAuthUser)->map([
        'id' => $id,
        'nickname' => $nickname,
        'name' => $name,
        'email' => $email,
    ]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($oauthUser);

    Socialite::shouldReceive('driver')->with('twitter-oauth-2')->andReturn($provider);
}

test('the redirect route sends the guest to X', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')->andReturn(redirect('https://x.com/i/oauth2/authorize?stub=1'));

    Socialite::shouldReceive('driver')->with('twitter-oauth-2')->andReturn($provider);

    $this->get(route('twitter.redirect'))
        ->assertRedirect('https://x.com/i/oauth2/authorize?stub=1');
});

test('a first X login registers a user with a personal team and signs in', function () {
    fakeTwitterCallback('19000001');

    $this->get(route('twitter.callback'))->assertRedirect(route('home'));

    $user = User::where('twitter_id', '19000001')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Lain')
        ->and($user->twitter_username)->toBe('lain')
        ->and($user->email)->toBe('twitter_19000001@localhost')
        ->and($user->personalTeam())->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('a returning X user logs into the same account and refreshes the handle', function () {
    $user = User::factory()->create([
        'twitter_id' => '19000002',
        'twitter_username' => 'old-handle',
    ]);

    fakeTwitterCallback('19000002', nickname: 'new-handle');

    $this->get(route('twitter.callback'))->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user->fresh());
    expect(User::where('twitter_id', '19000002')->count())->toBe(1)
        ->and($user->fresh()->twitter_username)->toBe('new-handle');
});

test('a signed-in user links the X account to their profile', function () {
    $user = User::factory()->create();

    fakeTwitterCallback('19000003');

    $this->actingAs($user)
        ->get(route('twitter.callback'))
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->twitter_id)->toBe('19000003')
        ->and($user->fresh()->twitter_username)->toBe('lain');
});

test('linking an X account owned by another user is refused', function () {
    User::factory()->create(['twitter_id' => '19000004']);
    $user = User::factory()->create();

    fakeTwitterCallback('19000004');

    $this->actingAs($user)
        ->get(route('twitter.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('error');

    expect($user->fresh()->twitter_id)->toBeNull();
});

test('a failed or cancelled OAuth callback bounces back to the login page', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andThrow(new RuntimeException('denied'));

    Socialite::shouldReceive('driver')->with('twitter-oauth-2')->andReturn($provider);

    $this->get(route('twitter.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});
