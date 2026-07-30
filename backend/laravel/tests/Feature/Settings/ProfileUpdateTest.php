<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile settings expose linked accounts and provider availability', function () {
    config()->set([
        'services.twitter-oauth-2.client_id' => 'twitter-client',
        'services.twitter-oauth-2.client_secret' => 'twitter-secret',
        'services.github.client_id' => 'github-client',
        'services.github.client_secret' => 'github-secret',
        'services.telegram.client_id' => '',
        'services.telegram.client_secret' => '',
    ]);

    $user = User::factory()->create([
        'github_id' => 'github-123',
        'github_username' => 'lain',
        'telegram_id' => 'telegram-123',
        'telegram_username' => 'lain_telegram',
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('canLinkTwitter', true)
            ->where('canLinkGitHub', true)
            ->where('canLinkTelegram', false)
            ->where('auth.user.github_id', 'github-123')
            ->where('auth.user.github_username', 'lain')
            ->where('auth.user.telegram_id', 'telegram-123')
            ->where('auth.user.telegram_username', 'lain_telegram'));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
