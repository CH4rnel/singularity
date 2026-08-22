<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Макет" — the design, kept inside the thing it describes.
 *
 * Two things are worth pinning. The artboards must still be on disk, because
 * the page is a directory listing of files nothing imports and a rename would
 * otherwise be silent. And the room must stay closed: the design shows real
 * names, real balances and the thresholds the console alerts on, so it is
 * behind the same allow list as the console itself.
 */
beforeEach(function () {
    $this->withoutVite();

    config()->set('crm.admin_wallets', ['0x00000000000000000000000000000000000000aa']);
    config()->set('crm.admin_user_ids', []);
});

function mockupOperator(): User
{
    return User::factory()->create([
        'wallet_address' => '0x00000000000000000000000000000000000000aa',
    ]);
}

it('lists every artboard of the canvas with its annotations', function () {
    $this->actingAs(mockupOperator())
        ->get('/crm/mockup')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Mockup')
            ->has('screens', 9)
            ->has('notes', 3)
            ->where('screens.0.key', 'main')
            ->where('screens.0.width', 1440)
            ->where('source', fn (string $url) => str_starts_with($url, 'https://'))
        );
});

it('serves one artboard as its own document', function () {
    $response = $this->actingAs(mockupOperator())->get('/crm/mockup/machines');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'");

    // The design has never had a script in it, and the sandboxed frame the
    // page uses is only the second line of that promise.
    expect($response->getContent())
        ->toContain('<x-dc>')
        ->not->toContain('<script');
});

it('knows only the screens the canvas names', function () {
    $this->actingAs(mockupOperator())->get('/crm/mockup/nonesuch')->assertNotFound();
});

it('is a 404 for everyone who is not an operator', function () {
    $stranger = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($stranger)->get('/crm/mockup')->assertNotFound();
    $this->actingAs($stranger)->get('/crm/mockup/main')->assertNotFound();
});

it('sends a guest to the login page rather than admitting the address exists', function () {
    $this->get('/crm/mockup')->assertRedirect('/login');
});

it('lets an operator in by account id when the wallet is not the one on file', function () {
    $operator = User::factory()->create(['wallet_address' => null]);

    config()->set('crm.admin_user_ids', [$operator->id]);

    $this->actingAs($operator)->get('/crm/mockup')->assertOk();

    config()->set('crm.admin_user_ids', [$operator->id + 1]);

    $this->actingAs($operator)->get('/crm/mockup')->assertNotFound();
});
