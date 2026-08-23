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
    $this->actingAs($stranger)->get(route('crm.people'))->assertNotFound();
    $this->actingAs($stranger)->get(route('crm.show', $contact))->assertNotFound();
    $this->actingAs($stranger)->get(route('crm.numbers'))->assertNotFound();
    $this->actingAs($stranger)->get(route('crm.machines'))->assertNotFound();
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

test('the people lens lists every segment with its count', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'Alice']);
    CrmContact::factory()->whale()->create(['name' => 'Bob']);

    $this->actingAs($user)
        ->get(route('crm.people'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/People')
            ->where('segment', 'all')
            ->where('total', 2)
            ->has('rows', 2)
            // A segment is a saved question, so the whole list travels with
            // its counts — an operator picks a question, not a filter.
            ->has('segments', 8)
            ->where('segments.0.key', 'all')
            ->where('segments.0.count', 2)
            ->where('segments.1.key', 'whales')
            ->where('segments.1.count', 1)
        );
});

test('a segment narrows the rows to the people its rule names', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'Alice']);
    CrmContact::factory()->whale()->create(['name' => 'Bob']);

    $this->actingAs($user)
        ->get(route('crm.people', ['segment' => 'whales']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('segment', 'whales')
            ->has('rows', 1)
            ->where('rows.0.name', 'Bob')
        );

    // An unknown segment is the whole base rather than an error: a stale
    // bookmark should open the lens, not a 404.
    $this->actingAs($user)
        ->get(route('crm.people', ['segment' => 'nonsense']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('segment', 'all'));
});

test('every row carries the freshest thing that happened to that person', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create(['name' => 'Alice']);
    $contact->notes()->create([
        'user_id' => $user->id,
        'type' => 'note',
        'body' => 'Asked about bridge limits',
    ]);

    $this->actingAs($user)
        ->get(route('crm.people'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.signal.key', 'signal.note')
            ->where('rows.0.signal.params.body', 'Asked about bridge limits')
        );
});

test('the search narrows the rows inside a segment', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    CrmContact::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $this->actingAs($user)
        ->get(route('crm.people', ['q' => 'alice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('total', 1));
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

test('a pasted profile link is stored as a bare handle', function () {
    $user = User::factory()->crmAdmin()->create();

    // Nobody transcribes a handle out of a profile they are looking at; they
    // copy the address bar. Three spellings of one handle in a column is a
    // link that works two times in three.
    $this->actingAs($user)
        ->post(route('crm.store'), [
            'name' => 'Carol',
            'telegram' => 'https://t.me/carol_here',
            'x_handle' => 'https://x.com/carol?s=20',
            'type' => 'lead',
            'status' => 'new',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('crm_contacts', [
        'name' => 'Carol',
        'telegram' => 'carol_here',
        'x_handle' => 'carol',
    ]);
});

test('a person already on the books is not added twice', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['x_handle' => 'fomo_person']);

    // The whole point of the composer is entering people in handfuls, which
    // is exactly when the same account gets typed twice.
    $this->actingAs($user)
        ->post(route('crm.store'), [
            'name' => 'Same person again',
            'x_handle' => 'https://x.com/fomo_person',
            'type' => 'lead',
            'status' => 'new',
        ])
        ->assertSessionHasErrors('x_handle');

    expect(CrmContact::count())->toBe(1);
});

test('a record keeps its own handle when it is edited', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create(['x_handle' => 'fomo_person']);

    $this->actingAs($user)
        ->put(route('crm.update', $contact), [
            'name' => 'Renamed',
            'x_handle' => 'fomo_person',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->name)->toBe('Renamed');
});

test('an X handle that could not have been one is refused', function () {
    $user = User::factory()->crmAdmin()->create();

    $this->actingAs($user)
        ->post(route('crm.store'), [
            'x_handle' => 'not a handle at all',
            'type' => 'lead',
            'status' => 'new',
        ])
        ->assertSessionHasErrors('x_handle');
});

test('a person found only on X is reachable from their row and their dossier', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create([
        'name' => 'Dave',
        'telegram' => null,
        'x_handle' => 'dave',
    ]);

    $this->actingAs($user)
        ->get(route('crm.people'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.write', 'https://x.com/dave')
            ->where('rows.0.action', 'write')
        );

    $this->actingAs($user)
        ->get(route('crm.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('contact.x_handle', 'dave')
            ->where('contact.x_url', 'https://x.com/dave')
            ->where('contact.telegram_url', null)
        );
});

test('a numeric telegram id is never offered as a way of writing', function () {
    $user = User::factory()->crmAdmin()->create();

    // What the sync knows about somebody who never set a username. `t.me/812…`
    // opens nothing, so the row's one action must not point at it.
    $contact = CrmContact::factory()->create([
        'telegram' => '819914001',
        'x_handle' => null,
    ]);

    $this->actingAs($user)
        ->get(route('crm.people'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.write', null)
            ->where('rows.0.action', 'dossier')
        );

    $this->actingAs($user)
        ->get(route('crm.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('contact.telegram_url', null));
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

test('editing the record corrects every field the operator was told', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create([
        'name' => 'Old name',
        'x_handle' => null,
        'tags' => ['fomo'],
    ]);

    $this->actingAs($user)
        ->put(route('crm.update', $contact), [
            'name' => 'New name',
            'telegram' => '@new_handle',
            'x_handle' => '@newname',
            'email' => '',
            'type' => 'holder',
            'status' => 'qualified',
            'tags' => ['fomo', 'wallet'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contact->refresh();

    expect($contact->name)->toBe('New name')
        ->and($contact->telegram)->toBe('new_handle')
        ->and($contact->x_handle)->toBe('newname')
        // A field cleared in the form is empty, not the string "".
        ->and($contact->email)->toBeNull()
        ->and($contact->type)->toBe('holder')
        ->and($contact->tags)->toBe(['fomo', 'wallet']);
});

test('the search finds a person by their X handle', function () {
    $user = User::factory()->crmAdmin()->create();
    CrmContact::factory()->create(['name' => 'Alice', 'x_handle' => 'alice_x']);
    CrmContact::factory()->create(['name' => 'Bob', 'x_handle' => null]);

    $this->actingAs($user)
        ->get(route('crm.people', ['q' => 'alice_x']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('total', 1));
});

test('a contact can be soft deleted', function () {
    $user = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create();

    $this->actingAs($user)
        ->delete(route('crm.destroy', $contact))
        ->assertRedirect(route('crm.people'));

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
