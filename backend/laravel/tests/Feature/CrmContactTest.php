<?php

use App\Models\CrmContact;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot reach the crm', function () {
    $this->get(route('crm.index'))->assertRedirect(route('login'));
});

test('authenticated users outside the allow list get a 404 everywhere in the crm', function () {
    $stranger = User::factory()->create(['wallet_address' => '0x'.str_repeat('e', 40)]);
    $contact = CrmContact::factory()->create();

    $this->actingAs($stranger)->get(route('crm.index'))->assertNotFound();
    $this->actingAs($stranger)->get(route('crm.show', $contact))->assertNotFound();
    $this->actingAs($stranger)->get(route('crm.analytics'))->assertNotFound();
    $this->actingAs($stranger)->get(route('crm.export'))->assertNotFound();
    $this->actingAs($stranger)->post(route('crm.sync'))->assertNotFound();
    $this->actingAs($stranger)->delete(route('crm.destroy', $contact))->assertNotFound();

    $this->assertDatabaseHas('crm_contacts', ['id' => $contact->id]);
});

test('a user with no wallet attached cannot reach the crm', function () {
    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)->get(route('crm.index'))->assertNotFound();
});

test('the allow list matches wallets regardless of case', function () {
    $user = User::factory()->create([
        'wallet_address' => '0xAFF26832DB3557DAF540B0B09DEE06C24B8A38BB',
    ]);

    $this->actingAs($user)->get(route('crm.index'))->assertOk();
});

test('both operator wallets are allowed', function () {
    foreach (config('crm.admin_wallets') as $wallet) {
        $user = User::factory()->create(['wallet_address' => $wallet]);

        $this->actingAs($user)->get(route('crm.index'))->assertOk();
    }

    expect(config('crm.admin_wallets'))->toBe([
        '0xaff26832db3557daf540b0b09dee06c24b8a38bb',
        '0x6f4afc4f18bd72a92d1c0087ea5fb79754652405',
    ]);
});

test('the index lists contacts with stats', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'Alice']);
    CrmContact::factory()->whale()->create(['name' => 'Bob']);

    $this->actingAs($user)
        ->get(route('crm.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Index')
            ->where('stats.total', 2)
            ->where('stats.whales', 1)
            ->has('contacts.data', 2)
        );
});

test('the search filter narrows the list', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    CrmContact::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $this->actingAs($user)
        ->get(route('crm.index', ['q' => 'alice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1));
});

test('the chain filter narrows the list and stats break down by chain', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'EvmOnly', 'solana_address' => null]);
    CrmContact::factory()->create(['name' => 'SolOnly', 'evm_address' => null]);
    CrmContact::factory()->create(['name' => 'BothChains']);

    $this->actingAs($user)
        ->get(route('crm.index', ['chain' => 'evm']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 2)
            ->where('stats.evm', 2)
            ->where('stats.solana', 2)
            ->where('stats.both', 1)
        );

    $this->actingAs($user)
        ->get(route('crm.index', ['chain' => 'solana']))
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 2));

    $this->actingAs($user)
        ->get(route('crm.index', ['chain' => 'both']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'BothChains')
        );

    $this->actingAs($user)
        ->get(route('crm.index', ['chain' => 'none']))
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 0));
});

test('a contact can be created', function () {
    $user = User::factory()->crmAdmin()->create();

    $this->actingAs($user)
        ->post(route('crm.store'), [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'type' => 'lead',
            'status' => 'new',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('crm_contacts', [
        'name' => 'Carol',
        'source' => 'manual',
    ]);
});

test('an invalid type is rejected', function () {
    $user = User::factory()->crmAdmin()->create();

    $this->actingAs($user)
        ->post(route('crm.store'), ['type' => 'banana', 'status' => 'new'])
        ->assertSessionHasErrors('type');
});

test('a contact can be updated', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create(['status' => 'new']);

    $this->actingAs($user)
        ->put(route('crm.update', $contact), ['status' => 'customer'])
        ->assertRedirect();

    expect($contact->refresh()->status)->toBe('customer');
});

test('a contact can be soft deleted', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create();

    $this->actingAs($user)
        ->delete(route('crm.destroy', $contact))
        ->assertRedirect(route('crm.index'));

    $this->assertSoftDeleted($contact);
});

test('a note can be added to a contact', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create();

    $this->actingAs($user)
        ->post(route('crm.notes.store', $contact), ['body' => 'Called, interested'])
        ->assertRedirect();

    $this->assertDatabaseHas('crm_notes', [
        'crm_contact_id' => $contact->id,
        'user_id' => $user->id,
        'body' => 'Called, interested',
    ]);
});

test('the export returns a csv download', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'Alice']);

    $response = $this->actingAs($user)->get(route('crm.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});
