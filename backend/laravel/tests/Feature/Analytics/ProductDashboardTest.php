<?php

use App\Models\AnalyticsUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The dashboard, and the two things that must be true of it: only operators
 * reach it, and it never prints an address.
 */
function operator(): User
{
    $wallet = config('crm.admin_wallets')[0];

    // One row per test: `wallet_address` is unique, and a test that signs in
    // twice must get the same operator rather than a constraint violation.
    return User::firstWhere('wallet_address', $wallet)
        ?? User::factory()->create(['wallet_address' => $wallet]);
}

function anonymousUser(array $attributes = []): AnalyticsUser
{
    $now = Carbon::now('UTC');

    return tap(new AnalyticsUser, fn (AnalyticsUser $user) => $user->forceFill([
        'id' => (string) Str::uuid(),
        'created_at' => $now,
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'platform' => 'web',
        'app_version' => 'v0.12.0',
        ...$attributes,
    ])->save());
}

test('the dashboard is invisible to everyone but a CRM operator', function () {
    $this->get('/crm/product')->assertRedirect();

    $this->actingAs(User::factory()->create())
        ->get('/crm/product')
        // 404 rather than 403: the CRM is not discoverable by an ordinary
        // signed-in user, and neither is this.
        ->assertNotFound();

    $this->actingAs(operator())->get('/crm/product')->assertOk();
});

test('the dashboard renders every section it promises', function () {
    $user = anonymousUser([
        'source' => 'twitter',
        'campaign' => 'launch',
        'wallet_created_at' => now(),
        'funded_at' => now(),
        'activated_at' => now(),
    ]);

    DB::table('analytics_events')->insert([
        'event_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'event' => 'swap_completed',
        'chain' => 'cyberia',
        'properties' => json_encode(['chain' => 'cyberia', 'amount_usd' => 25.0]),
        'created_at' => now(),
    ]);

    $this->actingAs(operator())
        ->get('/crm/product')
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Product')
            ->has('overview.north_star')
            ->where('overview.new_users', 1)
            // JSON has one number type; 25.0 arrives as 25.
            ->where('overview.swap_volume_usd', 25)
            ->has('series')
            ->has('mainFunnel', 5)
            ->has('productFunnels.swap')
            ->has('activation')
            ->has('cohorts')
            ->has('acquisition')
            ->has('usage')
            ->has('errors')
            ->has('gas')
            ->has('recent', 1)
        );
});

test('the explorer shows a timeline and never an address', function () {
    $user = anonymousUser(['funded_at' => now(), 'funded_chain' => 'cyberia']);

    DB::table('analytics_addresses')->insert([
        'user_id' => $user->id,
        'chain' => 'cyberia',
        'address' => '0xaaf26832db3557daf540b0b09dee06c24b8a38bb',
        'created_at' => now(),
    ]);

    DB::table('analytics_events')->insert([
        'event_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'event' => 'wallet_created',
        'created_at' => now(),
    ]);

    $response = $this->actingAs(operator())->get("/crm/product/users/{$user->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->component('crm/ProductUser')
        ->where('user.id', $user->id)
        // A count, because the addresses are held to read a balance and to
        // price a drip — neither of which anybody does by eye — and printing
        // them would turn this into a way of matching a wallet to a visitor.
        ->where('user.linked_addresses', 1)
        ->has('timeline', 1)
    );

    expect($response->content())->not->toContain('0xaaf26832db3557daf540b0b09dee06c24b8a38bb');
});

test('filters narrow what the dashboard reports', function () {
    anonymousUser(['source' => 'twitter', 'campaign' => 'launch']);
    anonymousUser(['source' => 'podcast', 'campaign' => 'ep12']);

    $this->actingAs(operator())
        ->get('/crm/product?source=twitter')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.source', 'twitter')
            ->where('overview.new_users', 1)
        );

    $this->actingAs(operator())
        ->get('/crm/product')
        ->assertInertia(fn (Assert $page) => $page->where('overview.new_users', 2));
});
