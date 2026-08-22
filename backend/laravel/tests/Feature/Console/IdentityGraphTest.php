<?php

use App\Models\CrmContact;
use App\Models\CrmIdentityLink;
use App\Models\User;
use App\Services\Console\IdentityGraph;

/**
 * Who is one person.
 *
 * The case that produced this: a visitor signed in with a Solana wallet, then
 * bridged from an EVM address, and the console filed two strangers — while
 * `bridge_requests` held the account, the sender and the recipient in a single
 * row. What is tested here is not the union-find; it is the judgement calls
 * around it, because those are what a wrong answer would come from — a guess
 * that merged two customers, or a link that quietly outlived being withdrawn.
 */
function graph(): IdentityGraph
{
    $graph = app(IdentityGraph::class);
    $graph->forget();

    return $graph;
}

function contact(array $attributes = []): CrmContact
{
    return CrmContact::create([
        'type' => 'lead',
        'status' => 'new',
        'source' => 'manual',
        ...$attributes,
    ]);
}

test('an account and the address that bridged under it are one person', function () {
    $user = User::factory()->create(['solana_wallet_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG']);

    $fromLogin = contact(['user_id' => $user->id, 'solana_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG']);
    $fromBridge = contact(['evm_address' => '0x89bb2e1cd29b15f0826c4e733a62ffbbb3cf127f', 'source' => 'bridge']);

    $graph = graph();
    $graph->link('user', (string) $user->id, 'evm', '0x89bb2E1CD29B15F0826c4E733a62FfBBB3CF127f', 'bridge_sender', 'bridge_requests #68');

    expect($graph->contactsWith($fromLogin)->pluck('id')->all())->toBe([$fromBridge->id])
        ->and($graph->contactsWith($fromBridge)->pluck('id')->all())->toBe([$fromLogin->id]);
});

test('an EVM address links whatever case it was written in', function () {
    // crm_contacts stores one casing and bridge_requests the other. A graph
    // that did not normalise would join nothing and look merely empty.
    $user = User::factory()->create();
    $lower = contact(['evm_address' => '0x89bb2e1cd29b15f0826c4e733a62ffbbb3cf127f']);
    contact(['user_id' => $user->id]);

    $graph = graph();
    $graph->link('user', (string) $user->id, 'evm', '0x89BB2E1CD29B15F0826C4E733A62FFBBB3CF127F', 'manual', null);

    expect($graph->contactsWith($lower))->toHaveCount(1);
});

test('a guess does not join a person on its own', function () {
    $user = User::factory()->create();
    $account = contact(['user_id' => $user->id]);
    contact(['solana_address' => 'FriendOfTheUser11111111111111111111111111111']);

    $graph = graph();
    // A bridge pays out to whatever address it was given, and people pay their
    // friends. Merging two customers on that would be worse than two records.
    $graph->link('user', (string) $user->id, 'solana', 'FriendOfTheUser11111111111111111111111111111', 'bridge_recipient', 'bridge_requests #1', 'weak');

    expect($graph->contactsWith($account))->toHaveCount(0);
});

test('a guess joins once somebody confirms it', function () {
    $user = User::factory()->create();
    $account = contact(['user_id' => $user->id]);
    $other = contact(['solana_address' => 'FriendOfTheUser11111111111111111111111111111']);

    $graph = graph();
    $link = $graph->link('user', (string) $user->id, 'solana', 'FriendOfTheUser11111111111111111111111111111', 'bridge_recipient', null, 'weak');

    $link->forceFill(['confidence' => 'strong'])->save();

    expect(graph()->contactsWith($account)->pluck('id')->all())->toBe([$other->id]);
});

test('the same claim from either end is one row', function () {
    $graph = graph();

    $graph->link('user', '7', 'evm', '0xAAaaAAaaAAaaAAaaAAaaAAaaAAaaAAaaAAaaAAaa', 'manual', null);
    $graph->link('evm', '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user', '7', 'manual', null);

    expect(CrmIdentityLink::count())->toBe(1);
});

test('a person is transitive across two links', function () {
    $user = User::factory()->create();
    $evm = contact(['evm_address' => '0x1111111111111111111111111111111111111111']);
    $sol = contact(['solana_address' => 'SoLaNa11111111111111111111111111111111111111']);
    contact(['user_id' => $user->id]);

    $graph = graph();
    $graph->link('user', (string) $user->id, 'evm', '0x1111111111111111111111111111111111111111', 'bridge_sender', null);
    $graph->link('user', (string) $user->id, 'solana', 'SoLaNa11111111111111111111111111111111111111', 'account', null);

    // The two addresses were never linked to each other directly. They are the
    // same person because both are the same account's.
    expect($graph->contactsWith($evm)->pluck('id')->all())->toContain($sol->id);
});

test('withdrawing a link takes the person apart again', function () {
    $user = User::factory()->create();
    $account = contact(['user_id' => $user->id]);
    contact(['evm_address' => '0x2222222222222222222222222222222222222222']);

    $graph = graph();
    $link = $graph->link('user', (string) $user->id, 'evm', '0x2222222222222222222222222222222222222222', 'manual', null);

    expect($graph->contactsWith($account))->toHaveCount(1);

    $link->delete();

    // A judgement that cannot be taken back is one people stop making.
    expect(graph()->contactsWith($account))->toHaveCount(0);
});

test('an address resolves to the account a notification can reach', function () {
    $user = User::factory()->create();

    $graph = graph();
    $graph->link('user', (string) $user->id, 'evm', '0x89bb2E1CD29B15F0826c4E733a62FfBBB3CF127f', 'bridge_sender', 'bridge_requests #68');

    expect($graph->userForAddress('evm', '0x89BB2E1CD29B15F0826C4E733A62FFBBB3CF127F')?->id)->toBe($user->id)
        ->and($graph->userForAddress('evm', '0x3333333333333333333333333333333333333333'))->toBeNull();
});

test('a record cannot be linked to itself', function () {
    $graph = graph();

    expect($graph->link('user', '9', 'user', '9', 'manual', null))->toBeNull()
        ->and(CrmIdentityLink::count())->toBe(0);
});
