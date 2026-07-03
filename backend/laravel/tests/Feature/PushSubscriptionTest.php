<?php

use App\Models\User;

test('guests cannot store push subscriptions', function () {
    $response = $this->postJson('/push-subscriptions', [
        'endpoint' => 'https://push.example.com/sub/abc',
        'keys' => ['p256dh' => 'key', 'auth' => 'token'],
    ]);

    $response->assertUnauthorized();
});

test('authenticated users can store a push subscription', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/push-subscriptions', [
        'endpoint' => 'https://push.example.com/sub/abc',
        'keys' => ['p256dh' => 'key', 'auth' => 'token'],
    ]);

    $response->assertOk()->assertJsonPath('subscribed', true);
    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'endpoint' => 'https://push.example.com/sub/abc',
    ]);
});

test('push subscription requires endpoint and keys', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/push-subscriptions', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint', 'keys']);
});

test('authenticated users can delete a push subscription', function () {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://push.example.com/sub/abc', 'key', 'token');

    $response = $this->actingAs($user)->deleteJson('/push-subscriptions', [
        'endpoint' => 'https://push.example.com/sub/abc',
    ]);

    $response->assertOk()->assertJsonPath('subscribed', false);
    $this->assertDatabaseMissing('push_subscriptions', [
        'endpoint' => 'https://push.example.com/sub/abc',
    ]);
});
