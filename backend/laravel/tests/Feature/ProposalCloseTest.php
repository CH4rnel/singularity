<?php

use App\Models\Activity;
use App\Models\Dao;
use App\Models\Proposal;
use App\Models\User;

/**
 * Ending a vote before its deadline. The deadline is what normally closes a
 * proposal; this is the lever for the ones it cannot answer, and it is the
 * one action here that reaches past the author.
 */
test('the author can end the voting now', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->open()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/close");

    $response->assertRedirect();
    expect($proposal->fresh()->status)->toBe('closed');
});

test('the dao owner can end a vote they did not open', function () {
    $owner = User::factory()->create();
    $dao = Dao::factory()->create(['user_id' => $owner->id]);
    $proposal = Proposal::factory()->open()->create(['dao_id' => $dao->id]);

    $this->actingAs($owner)->post("/proposals/{$proposal->id}/close");

    expect($proposal->fresh()->status)->toBe('closed');
});

test('an operator can end a vote in a dao with no owner', function () {
    $operator = User::factory()->create();
    config(['crm.admin_user_ids' => [$operator->id]]);

    $dao = Dao::factory()->create(['user_id' => null]);
    $proposal = Proposal::factory()->open()->create(['dao_id' => $dao->id]);

    $this->actingAs($operator)->post("/proposals/{$proposal->id}/close");

    expect($proposal->fresh()->status)->toBe('closed');
});

test('a bystander cannot end a vote', function () {
    // The operator list comes from the environment; a test account must not
    // inherit the console by having been numbered the same as an operator.
    config()->set('crm.admin_user_ids', []);

    $proposal = Proposal::factory()->open()->create();

    $response = $this->actingAs(User::factory()->create())
        ->post("/proposals/{$proposal->id}/close");

    $response->assertForbidden();
    expect($proposal->fresh()->status)->toBe('open');
});

test('guests cannot end a vote', function () {
    $proposal = Proposal::factory()->open()->create();

    $this->post("/proposals/{$proposal->id}/close")->assertRedirect('/login');

    expect($proposal->fresh()->status)->toBe('open');
});

test('closing a vote that already ended leaves its deadline alone', function () {
    $user = User::factory()->create();
    $endedAt = now()->subDays(3);
    $proposal = Proposal::factory()->create([
        'user_id' => $user->id,
        'ends_at' => $endedAt,
    ]);

    $this->actingAs($user)->post("/proposals/{$proposal->id}/close");

    expect($proposal->fresh()->ends_at->timestamp)->toBe($endedAt->timestamp);
});

test('closing is recorded in the feed and pays no xp', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->open()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post("/proposals/{$proposal->id}/close");

    $this->assertDatabaseHas('activities', [
        'type' => 'proposal.closed',
        'user_id' => $user->id,
        'subject_type' => Proposal::class,
        'subject_id' => $proposal->id,
    ]);

    expect(Activity::where('type', 'proposal.closed')->count())->toBe(1);
    $this->assertDatabaseMissing('xp_entries', ['action' => 'proposal.closed']);
});

test('a closed vote refuses new votes', function () {
    $author = User::factory()->create();
    $proposal = Proposal::factory()->open()->create(['user_id' => $author->id]);

    $this->actingAs($author)->post("/proposals/{$proposal->id}/close");

    $response = $this->actingAs(User::factory()->create())
        ->post("/proposals/{$proposal->id}/votes", [
            'wallet_address' => '0x1234567890abcdef1234567890abcdef12345678',
            'support' => true,
        ]);

    $response->assertForbidden();
});

/**
 * The button is drawn from the props, so the props have to carry the owner.
 * Trimming this relation to id+name would silently take the close button away
 * from every DAO owner while leaving the route working.
 */
test('the proposal page carries the dao owner the close button reads', function () {
    $owner = User::factory()->create();
    $dao = Dao::factory()->create(['user_id' => $owner->id]);
    $proposal = Proposal::factory()->open()->create(['dao_id' => $dao->id]);

    $this->actingAs($owner)
        ->get("/proposals/{$proposal->id}")
        ->assertInertia(fn ($page) => $page
            ->where('proposal.dao.user_id', $owner->id)
            ->where('proposal.status', 'open'));
});
