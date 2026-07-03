<?php

use App\Models\Dao;
use App\Models\Proposal;
use App\Models\User;

test('guests can view dao page', function () {
    $dao = Dao::factory()->create();

    $response = $this->get("/dao/{$dao->id}");

    $response->assertOk();
});

test('authenticated users can view dao with proposals', function () {
    $user = User::factory()->create();
    $dao = Dao::factory()->create();

    $response = $this->actingAs($user)->get("/dao/{$dao->id}");

    $response->assertOk();
});

test('authenticated users can create a proposal', function () {
    $user = User::factory()->create();
    $dao = Dao::factory()->create();

    $response = $this->actingAs($user)->post("/dao/{$dao->id}/proposals", [
        'dao_id' => $dao->id,
        'title' => 'Test Proposal',
        'description' => 'Some description',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('proposals', [
        'dao_id' => $dao->id,
        'user_id' => $user->id,
        'title' => 'Test Proposal',
    ]);
});

test('proposal creation requires title', function () {
    $user = User::factory()->create();
    $dao = Dao::factory()->create();

    $response = $this->actingAs($user)->post("/dao/{$dao->id}/proposals", [
        'dao_id' => $dao->id,
    ]);

    $response->assertSessionHasErrors('title');
});

test('proposal creation requires valid dao_id', function () {
    $user = User::factory()->create();
    $dao = Dao::factory()->create();

    $response = $this->actingAs($user)->post("/dao/{$dao->id}/proposals", [
        'dao_id' => 99999,
        'title' => 'Test',
    ]);

    $response->assertSessionHasErrors('dao_id');
});

test('authors can update their proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['title' => 'Old Title', 'user_id' => $user->id]);

    $response = $this->actingAs($user)->put("/proposals/{$proposal->id}", [
        'title' => 'New Title',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('proposals', [
        'id' => $proposal->id,
        'title' => 'New Title',
    ]);
});

test('authors can close a proposal by moving its deadline', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->open()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->put("/proposals/{$proposal->id}", [
        'ends_at' => now()->subMinute()->toDateTimeString(),
    ]);

    $response->assertRedirect();
    expect($proposal->fresh()->status)->toBe('closed');
});

test('non-authors cannot update a proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $response = $this->actingAs($user)->put("/proposals/{$proposal->id}", [
        'title' => 'Hijacked',
    ]);

    $response->assertForbidden();
});

test('authors can delete their proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete("/proposals/{$proposal->id}");

    $response->assertRedirect();
    $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
});

test('authenticated users can view a proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $response = $this->actingAs($user)->get("/proposals/{$proposal->id}");

    $response->assertOk();
});
