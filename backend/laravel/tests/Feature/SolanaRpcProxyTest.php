<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The relay exists for one reason: Solana's public cluster answers this server
 * and refuses the browser — same call, `403 Access forbidden`, as soon as it
 * carries an `Origin`. The endpoints that do answer browsers want a key in the
 * URL, and a key in a bundle is a key anyone may spend.
 *
 * So what is pinned here is the shape of a relay rather than a wallet: it
 * forwards, it never holds a key, it refuses what it was not asked to forward,
 * and a dead upstream is somebody else's turn rather than an outage.
 */
beforeEach(function () {
    config([
        'solana.rpc.enabled' => true,
        'solana.rpc.upstreams.mainnet' => ['https://keyed.test/?api-key=secret', 'https://public.test'],
        'solana.rpc.upstreams.devnet' => ['https://devnet.test'],
        'solana.rpc.origins' => [],
    ]);

    Cache::flush();
});

$call = fn (string $method = 'getBalance', array $params = ['addr']) => [
    'jsonrpc' => '2.0',
    'id' => 7,
    'method' => $method,
    'params' => $params,
];

it('forwards an allowed call and hands back what Solana said', function () use ($call) {
    Http::fake([
        'keyed.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 7, 'result' => ['value' => 42]]),
    ]);

    $this->postJson('/api/solana/rpc', $call())
        ->assertOk()
        ->assertJson(['id' => 7, 'result' => ['value' => 42]]);

    Http::assertSent(fn ($request) => $request->url() === 'https://keyed.test/?api-key=secret'
        && $request['method'] === 'getBalance');
});

/**
 * The whole point of an ordered list: an exhausted key replies `max usage
 * reached` in plain text with an HTTP error, which is a refusal and not an
 * answer, so the next upstream gets the call and the user gets a balance.
 */
it('falls through to the next upstream when the first refuses', function () use ($call) {
    Http::fake([
        'keyed.test*' => Http::response('max usage reached', 429),
        'public.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 7, 'result' => 1]),
    ]);

    $this->postJson('/api/solana/rpc', $call())
        ->assertOk()
        ->assertJson(['result' => 1]);
});

/**
 * Free tiers disagree about which calls they serve, so a provider's gap is a
 * reason to ask the next one — not the answer the wallet renders as "you have
 * no tokens".
 */
it('hands the call on when an upstream does not serve the method', function () use ($call) {
    Http::fake([
        'keyed.test*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => ['code' => -32601, 'message' => 'Method not found'],
        ]),
        'public.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 7, 'result' => 'served']),
    ]);

    $this->postJson('/api/solana/rpc', $call('getTokenAccountsByOwner', ['addr']))
        ->assertOk()
        ->assertJson(['result' => 'served']);
});

it('works down the whole stack, in the order given', function () {
    config(['solana.rpc.upstreams.mainnet' => [
        'https://first.test', 'https://second.test', 'https://third.test',
    ]]);

    Http::fake([
        'first.test*' => Http::response('over quota', 429),
        'second.test*' => Http::response('over quota', 429),
        'third.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'third']),
    ]);

    $this->postJson('/api/solana/rpc', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'getBalance', 'params' => ['addr'],
    ])->assertOk()->assertJson(['result' => 'third']);

    Http::assertSentCount(3);
});

it('reports every upstream failing as a JSON-RPC error, naming none of them', function () use ($call) {
    Http::fake(['*' => Http::response('nope', 500)]);

    $response = $this->postJson('/api/solana/rpc', $call());

    $response->assertStatus(502)->assertJsonPath('error.code', -32603);

    expect($response->getContent())
        ->not->toContain('api-key')
        ->not->toContain('keyed.test');
});

/**
 * A method name says nothing about what a call costs — `getProgramAccounts`
 * reads an entire program's state — so the allowlist is the gate, and it
 * answers before anything is spent upstream.
 */
it('refuses a method it was not asked to relay, without calling out', function () use ($call) {
    Http::fake();

    $this->postJson('/api/solana/rpc', $call('getProgramAccounts', []))
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32601)
        ->assertJsonPath('id', 7);

    Http::assertNothingSent();
});

it('relays a batch, because a transaction history is read as one', function () {
    Http::fake([
        'keyed.test*' => Http::response([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'a'],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => 'b'],
        ]),
    ]);

    $this->postJson('/api/solana/rpc', [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'getTransaction', 'params' => ['sig-a']],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'getTransaction', 'params' => ['sig-b']],
    ])
        ->assertOk()
        ->assertJsonCount(2);

    Http::assertSentCount(1);
});

it('rejects a batch larger than the cap, so one request cannot spend a hundred calls', function () {
    config(['solana.rpc.max_batch' => 2]);
    Http::fake();

    $batch = array_fill(0, 3, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'getTransaction', 'params' => ['sig']]);

    $this->postJson('/api/solana/rpc', $batch)->assertStatus(400);

    Http::assertNothingSent();
});

it('refuses a batch member that is not allowed, along with the batch', function () {
    Http::fake();

    $this->postJson('/api/solana/rpc', [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'getTransaction', 'params' => ['sig']],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'getProgramAccounts', 'params' => []],
    ])->assertStatus(400);

    Http::assertNothingSent();
});

/**
 * The public cluster's limits are per IP, and behind a relay that IP is this
 * server for every visitor at once — so a chain-wide read is asked once and
 * handed to everyone who wanted it that second.
 */
it('answers a chain-wide read from cache and asks upstream once', function () {
    config(['solana.rpc.cache.getLatestBlockhash' => 5]);
    // A node echoes the id it was given, so the fake does too — that is what
    // makes the cached answer's rebuilt envelope indistinguishable from a live
    // one.
    Http::fake([
        'keyed.test*' => fn ($request) => Http::response([
            'jsonrpc' => '2.0',
            'id' => $request->data()['id'],
            'result' => ['blockhash' => 'hash'],
        ]),
    ]);

    foreach ([11, 22] as $id) {
        $this->postJson('/api/solana/rpc', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'getLatestBlockhash',
            'params' => [],
        ])
            ->assertOk()
            // The caller's own id comes back, never the id of whoever warmed
            // the cache — a client matching answers to requests still matches.
            ->assertJsonPath('id', $id)
            ->assertJsonPath('result.blockhash', 'hash');
    }

    Http::assertSentCount(1);
});

it('never caches an account read or anything that moves', function () {
    Http::fake([
        'keyed.test*' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 10])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 20]),
    ]);

    $balance = fn () => $this->postJson('/api/solana/rpc', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'getBalance', 'params' => ['addr'],
    ])->json('result');

    expect($balance())->toBe(10)
        ->and($balance())->toBe(20);
});

it('relays a cluster of its own when one is named', function () {
    Http::fake(['devnet.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'ok'])]);

    $this->postJson('/api/solana/rpc/devnet', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'getHealth', 'params' => [],
    ])->assertOk();

    $this->postJson('/api/solana/rpc/nosuchnet', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'getHealth', 'params' => [],
    ])->assertStatus(404);
});

it('honours an origin allowlist when the host sets one', function () use ($call) {
    config(['solana.rpc.origins' => ['*.cyberia.church', 'cyberia.church']]);
    Http::fake(['keyed.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 7, 'result' => 1])]);

    $this->postJson('/api/solana/rpc', $call(), ['Origin' => 'https://bridge.cyberia.church'])->assertOk();

    // A call with no Origin at all is a script or another server, not the
    // browser abuse this guards against.
    $this->postJson('/api/solana/rpc', $call())->assertOk();

    $this->postJson('/api/solana/rpc', $call(), ['Origin' => 'https://someone-else.example'])
        ->assertStatus(403);
});

/**
 * The env these upstreams come from is shared with the bridge and the slot
 * machine, and the machine runs on devnet — prod's `.env` already carries a
 * devnet URL under a name the mainnet list reads. A devnet node answers a
 * mainnet address with zero rather than with an error, so the wrong endpoint
 * would not fail, it would quietly say every account is empty.
 */
it('keeps a devnet endpoint out of the mainnet list, and the reverse', function () use ($call) {
    config([
        'solana.rpc.upstreams.mainnet' => ['https://api.devnet.solana.com', 'https://public.test'],
        'solana.rpc.upstreams.devnet' => ['https://api.mainnet-beta.solana.com', 'https://devnet.test'],
    ]);

    Http::fake([
        'public.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 7, 'result' => 1]),
        'devnet.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 7, 'result' => 2]),
    ]);

    $this->postJson('/api/solana/rpc', $call())->assertOk()->assertJson(['result' => 1]);
    $this->postJson('/api/solana/rpc/devnet', $call())->assertOk()->assertJson(['result' => 2]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'solana.com'));
});

/**
 * One free tier is a single point of failure, so the fallback variable takes a
 * list: stacking three providers is the difference between a daily cap being
 * theirs and it being ours.
 */
it('reads a comma-separated fallback list out of the environment', function () {
    $_ENV['SOLANA_RPC_FALLBACK_URL'] = 'https://a.test, https://b.test';
    $_SERVER['SOLANA_RPC_FALLBACK_URL'] = 'https://a.test, https://b.test';

    try {
        $upstreams = (require config_path('solana.php'))['rpc']['upstreams']['mainnet'];
    } finally {
        unset($_ENV['SOLANA_RPC_FALLBACK_URL'], $_SERVER['SOLANA_RPC_FALLBACK_URL']);
    }

    expect($upstreams)->toContain('https://a.test')
        ->toContain('https://b.test')
        // The official cluster is always the floor under whatever was named.
        ->toContain('https://api.mainnet-beta.solana.com');
});

it('says so plainly when it is switched off', function () use ($call) {
    config(['solana.rpc.enabled' => false]);
    Http::fake();

    $this->postJson('/api/solana/rpc', $call())->assertStatus(503);

    Http::assertNothingSent();
});
