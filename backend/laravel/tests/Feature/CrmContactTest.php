<?php

use App\Models\CrmContact;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot reach the crm', function () {
    $this->get(route('crm.index'))->assertRedirect(route('login'));
});

test('the index lists contacts with stats', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    CrmContact::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    CrmContact::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $this->actingAs($user)
        ->get(route('crm.index', ['q' => 'alice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1));
});

test('a contact can be created', function () {
    $user = User::factory()->create();

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
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('crm.store'), ['type' => 'banana', 'status' => 'new'])
        ->assertSessionHasErrors('type');
});

test('a contact can be updated', function () {
    $user = User::factory()->create();
    $contact = CrmContact::factory()->create(['status' => 'new']);

    $this->actingAs($user)
        ->put(route('crm.update', $contact), ['status' => 'customer'])
        ->assertRedirect();

    expect($contact->refresh()->status)->toBe('customer');
});

test('a contact can be soft deleted', function () {
    $user = User::factory()->create();
    $contact = CrmContact::factory()->create();

    $this->actingAs($user)
        ->delete(route('crm.destroy', $contact))
        ->assertRedirect(route('crm.index'));

    $this->assertSoftDeleted($contact);
});

test('a note can be added to a contact', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    CrmContact::factory()->create(['name' => 'Alice']);

    $response = $this->actingAs($user)->get(route('crm.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});
