<?php

use App\Models\LaunchpadToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * underground.cyberia.church: the directory of token homepages, its ring and
 * its counters. What is pinned is what a visitor would notice being wrong —
 * a homepage missing from the directory, a ring that skips a stop or never
 * reaches the hidden one, a counter that counts a refresh as a visitor.
 */
beforeEach(function () {
    Cache::flush();
    Storage::fake('public');
});

it('lists homepages and vacant lots, and hides two things for the terminal', function () {
    LaunchpadToken::factory()->withSite('minecraft')->create(['symbol' => 'MINE', 'name' => 'Minecraft']);
    LaunchpadToken::factory()->create(['symbol' => 'IRC', 'name' => 'Internet Relay Chat']);

    $this->get('https://underground.cyberia.church/')
        ->assertOk()
        ->assertSee('minecraft.cyberia.church')
        ->assertSee('Minecraft')
        ->assertSee('Internet Relay Chat')
        ->assertSee('$IRC')
        // A comment in the source and a header: two of the underground's secrets.
        ->assertSee('the word is: geocities', false)
        ->assertHeader('X-Wired', 'knights');
});

it('escapes whatever a creator typed into a name', function () {
    LaunchpadToken::factory()->create(['name' => '<script>alert(1)</script>']);

    $this->get('https://underground.cyberia.church/')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('rides the ring through the stop past the last homepage', function () {
    LaunchpadToken::factory()->withSite('alpha')->create();
    LaunchpadToken::factory()->withSite('beta')->create();
    LaunchpadToken::factory()->create();

    $ring = 'https://underground.cyberia.church/ring';

    $this->get("$ring/next/alpha")->assertRedirect('https://beta.cyberia.church/');
    $this->get("$ring/next/beta")->assertRedirect('https://underground.cyberia.church/lost');
    $this->get("$ring/next/lost")->assertRedirect('https://alpha.cyberia.church/');
    $this->get("$ring/prev/alpha")->assertRedirect('https://underground.cyberia.church/lost');
    $this->get("$ring/prev/lost")->assertRedirect('https://beta.cyberia.church/');
    // Arriving from nowhere starts at an end.
    $this->get("$ring/next")->assertRedirect('https://alpha.cyberia.church/');

    $this->get('https://underground.cyberia.church/lost')
        ->assertOk()
        ->assertSee('NO CARRIER', false);
});

it('never rides somebody back to the page they left', function () {
    LaunchpadToken::factory()->withSite('alpha')->create();
    LaunchpadToken::factory()->withSite('beta')->create();

    foreach (range(1, 20) as $ride) {
        expect($this->get('https://underground.cyberia.church/ring/random/alpha')->headers->get('Location'))
            ->toBeIn(['https://beta.cyberia.church/', 'https://underground.cyberia.church/lost']);
    }
});

it('counts one visitor per address per day, and a visit is not an edit', function () {
    $token = LaunchpadToken::factory()->withSite('minecraft')->create(['updated_at' => now()->subWeek()]);
    Storage::disk('public')->put($token->html_path, '<h1>blocks</h1>');

    $this->get('https://minecraft.cyberia.church/')->assertOk();
    $this->get('https://minecraft.cyberia.church/about')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->get('https://minecraft.cyberia.church/')
        ->assertOk();

    $token->refresh();

    expect($token->site_hits)->toBe(2)
        ->and($token->updated_at->isSameDay(now()->subWeek()))->toBeTrue();

    $this->get('https://underground.cyberia.church/counter/minecraft.svg')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertSee('0000002', false);
});

it('draws the same cover for the same token, labelled with its ticker', function () {
    $token = LaunchpadToken::factory()->create(['symbol' => 'DNS']);
    $url = 'https://underground.cyberia.church/cover/'.$token->address.'.svg';

    $first = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

    expect($first->getContent())->toContain('$DNS')
        ->toBe($this->get($url)->getContent());
});

it('hands the wallet the directory as data', function () {
    LaunchpadToken::factory()->withSite('minecraft')->create(['symbol' => 'MINE']);
    LaunchpadToken::factory()->create(['symbol' => 'TOS']);

    $this->getJson('/api/underground')
        ->assertOk()
        ->assertJsonPath('underground_url', 'https://underground.cyberia.church')
        ->assertJsonPath('ring', ['minecraft'])
        ->assertJsonPath('homes.0.site_url', 'https://minecraft.cyberia.church/')
        ->assertJsonPath('homes.0.counter_url', 'https://underground.cyberia.church/counter/minecraft.svg')
        ->assertJsonPath('lots.0.symbol', 'TOS')
        ->assertJsonPath('lots.0.site_url', null);
});
