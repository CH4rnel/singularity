<?php

use App\Models\User;

test('session probe reports guests as unauthenticated', function () {
    $this->getJson('/api/session-user')
        ->assertOk()
        ->assertExactJson(['authenticated' => false]);
});

test('session probe returns the logged-in user name', function () {
    $user = User::factory()->create(['name' => 'Neo']);

    $this->actingAs($user)
        ->getJson('/api/session-user')
        ->assertOk()
        ->assertJson(['authenticated' => true, 'name' => 'Neo']);
});
