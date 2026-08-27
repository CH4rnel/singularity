<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    config()->set('crm.admin_wallets', ['0x00000000000000000000000000000000000000aa']);
    config()->set('crm.admin_user_ids', []);
});

function strategyOperator(): User
{
    return User::factory()->create([
        'wallet_address' => '0x00000000000000000000000000000000000000aa',
    ]);
}

it('shows the strategy lens and serves the uploaded report as an inert document', function () {
    $operator = strategyOperator();

    $this->actingAs($operator)
        ->get(route('crm.strategy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Strategy')
            ->where('edited', false)
            ->where('updatedAt', null));

    $document = $this->actingAs($operator)->get(route('crm.strategy.document'));

    $document->assertOk();
    $document->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $document->assertHeader('Cache-Control', 'no-store, private');
    expect($document->getContent())
        ->toContain('Контент-стратегия Cyberia на 30 дней')
        ->not->toContain('<script');
});

it('saves only inert strategy markup and can restore the uploaded original', function () {
    $operator = strategyOperator();

    $this->actingAs($operator)
        ->put(route('crm.strategy.update'), [
            'html' => '<!doctype html><html><body onclick="steal()"><h1>Рабочая версия</h1><script>alert(1)</script><iframe src="https://example.com"></iframe></body></html>',
        ])
        ->assertRedirect();

    Storage::disk('local')->assertExists('console/content-strategy.html');
    expect(Storage::disk('local')->get('console/content-strategy.html'))
        ->toContain('Рабочая версия')
        ->not->toContain('onclick')
        ->not->toContain('<script')
        ->not->toContain('<iframe');

    $this->actingAs($operator)
        ->get(route('crm.strategy'))
        ->assertInertia(fn (Assert $page) => $page->where('edited', true));

    $this->actingAs($operator)
        ->delete(route('crm.strategy.reset'))
        ->assertRedirect();

    Storage::disk('local')->assertMissing('console/content-strategy.html');
});

it('keeps the strategy private behind the console gate', function () {
    $stranger = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($stranger)->get(route('crm.strategy'))->assertNotFound();
    $this->actingAs($stranger)->get(route('crm.strategy.document'))->assertNotFound();
});

it('sends a guest to login without revealing the strategy', function () {
    $this->get(route('crm.strategy'))->assertRedirect(route('login'));
});
