<?php

use App\Models\AiApiKey;
use App\Models\AiApiRequest;
use App\Services\Ai\AiKeyService;
use Elliptic\EC;
use Elliptic\EC\KeyPair;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use kornrunner\Keccak;

/**
 * The Cyberia inference API.
 *
 * Everything here runs against a faked chain and faked providers, because the
 * three things worth pinning are ours: who is let in, which upstream is
 * chosen, and what is written down afterwards.
 *
 * The signing key is generated per test and never leaves memory — the gate is
 * about proving an address, and a fixture key would be a private key checked
 * into the repository for no reason.
 */
const AI_RPC_URL = 'https://rpc.cyberia.church';
const AI_GROQ_URL = 'https://api.groq.com/openai/v1/chat/completions';
const AI_OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
const AI_SUPPLY = '1000000000000000000000';
const AI_ONE_PERCENT = '10000000000000000000';
const AI_TENTH_PERCENT = '1000000000000000000';

beforeEach(function () {
    config()->set('ai.providers.groq.api_key', 'test-groq-key');
    config()->set('ai.providers.openrouter.api_key', 'test-openrouter-key');
    config()->set('ai.gate.minimum_share_bps', 50);
    config()->set('ai.limits.requests_per_minute', 20);
    config()->set('ai.limits.requests_per_day', 2000);
});

function aiHex(string $decimal): string
{
    $hex = '';

    while (bccomp($decimal, '0') > 0) {
        $hex = dechex((int) bcmod($decimal, '16')).$hex;
        $decimal = bcdiv($decimal, '16', 0);
    }

    return '0x'.($hex === '' ? '0' : $hex);
}

/**
 * Fake the two reads the gate makes: balanceOf, then totalSupply.
 *
 * Both the balance and whether the node answers at all are read out of the
 * container at call time rather than closed over, so a test can move either
 * mid-flight — which is the whole point of a gate that re-checks instead of
 * trusting the proof it once issued. (Http::fake() merges rather than
 * replaces, so a later fake could not override this stub anyway.)
 */
function aiChain(string $balance): void
{
    app()->instance('ai.balance', $balance);
    app()->instance('ai.rpc_up', true);

    Http::fake([
        AI_RPC_URL => function (Request $request) {
            if (! app('ai.rpc_up')) {
                return Http::response('gateway down', 502);
            }

            $data = (string) ($request->data()['params'][0]['data'] ?? '');

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => aiHex(str_starts_with($data, '0x18160ddd') ? AI_SUPPLY : app('ai.balance')),
            ]);
        },
    ]);
}

/** @return array{address: string, pair: KeyPair} */
function aiWallet(): array
{
    $pair = (new EC('secp256k1'))->genKeyPair();
    $public = $pair->getPublic('hex');
    $address = '0x'.substr(Keccak::hash(hex2bin(substr($public, 2)), 256), 24);

    return ['address' => strtolower($address), 'pair' => $pair];
}

function aiSign(KeyPair $pair, string $message): string
{
    $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
    $signature = $pair->sign($hash, ['canonical' => true]);

    return '0x'
        .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
        .dechex(27 + $signature->recoveryParam);
}

/** Prove an address and walk away with a key. */
function aiIssueKey(array $wallet, ?string $name = null): string
{
    $challenge = test()->postJson('/api/ai/keys/nonce', ['address' => $wallet['address']])
        ->assertOk()
        ->json('message');

    return test()->postJson('/api/ai/keys', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
        'name' => $name,
    ])->assertCreated()->json('key');
}

function aiCompletion(string $model = 'llama-3.1-8b-instant', string $text = 'hello from the wired'): array
{
    return [
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $text],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18],
    ];
}

it('describes itself and its catalogue without a key', function () {
    $this->getJson('/api/ai/v1')
        ->assertOk()
        ->assertJsonPath('gate.symbol', 'LAIN')
        ->assertJsonPath('gate.minimum_share', '0.5%')
        ->assertJsonPath('limits.requests_per_minute', 20);

    $models = $this->getJson('/api/ai/v1/models')->assertOk()->json('data');

    expect(array_column($models, 'id'))->toContain('lain-fast', 'lain-free');
});

it('hides models whose provider has no key on this server', function () {
    config()->set('ai.providers.groq.api_key', null);

    $ids = array_column($this->getJson('/api/ai/v1/models')->assertOk()->json('data'), 'id');

    expect($ids)->toContain('lain-free')->not->toContain('lain-fast');
});

it('carries the complete free claude code provider catalogue', function () {
    $providers = array_keys((array) config('ai.providers'));

    expect($providers)->toBe([
        'nvidia_nim',
        'openrouter',
        'groq',
        'cline_pass',
        'openai',
        'xai',
        'qwencloud',
        'qwencloud_coding',
        'together',
        'deepinfra',
        'siliconflow',
        'nebius',
        'chutes',
        'featherless',
        'agnes',
        'zenmux',
        'wandb',
        'azure_openai',
        'gemini',
        'vertex',
        'deepseek',
        'mistral',
        'mistral_codestral',
        'opencode_zen',
        'opencode_go',
        'vercel',
        'bedrock',
        'huggingface',
        'cohere',
        'github_models',
        'wafer',
        'kimi',
        'kimi_code',
        'kilo',
        'minimax',
        'cerebras',
        'sambanova',
        'fireworks',
        'novita',
        'cloudflare',
        'zai',
        'zai_api',
        'tokenrouter',
        'nararoute',
        'poolside',
        'ollama_cloud',
        'lmstudio',
        'llamacpp',
        'ollama',
    ]);
});

it('calls a declarative provider with its request policy', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());
    config()->set('ai.providers.minimax.api_key', 'test-minimax-key');

    $url = 'https://api.minimax.io/v1/chat/completions';

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        $url => Http::response(aiCompletion('MiniMax-M3')),
    ]);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-minimax',
        'messages' => [['role' => 'user', 'content' => 'hi']],
        'max_tokens' => 123,
    ])
        ->assertOk()
        ->assertJsonPath('model', 'lain-minimax')
        ->assertJsonPath('provider', 'minimax');

    Http::assertSent(fn (Request $request) => $request->url() === $url
        && $request->header('Authorization')[0] === 'Bearer test-minimax-key'
        && $request->data()['model'] === 'MiniMax-M3'
        && $request->data()['max_completion_tokens'] === 123
        && ! array_key_exists('max_tokens', $request->data()));
});

it('issues a key to an address that holds enough, and only shows it once', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();

    $key = aiIssueKey($wallet, 'laptop');

    expect($key)->toStartWith(AiKeyService::PREFIX);

    $record = AiApiKey::sole();
    expect($record->address)->toBe($wallet['address'])
        ->and($record->name)->toBe('laptop')
        ->and($record->token_hash)->toBe(hash('sha256', $key))
        ->and($record->toPublicArray())->not->toHaveKey('token_hash');
});

it('binds a LainOS key to the installation that authorised it', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();
    $instanceId = (string) Str::uuid();
    $challenge = $this->postJson('/api/ai/keys/nonce', ['address' => $wallet['address']])
        ->assertOk()
        ->json('message');

    $this->postJson('/api/ai/keys', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
        'client' => 'lainos',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('instance_id');

    $this->postJson('/api/ai/keys', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
        'name' => 'lain bedroom',
        'client' => 'lainos',
        'instance_id' => $instanceId,
    ])
        ->assertCreated()
        ->assertJsonPath('record.client', 'lainos')
        ->assertJsonPath('record.instance_id', $instanceId);

    $record = AiApiKey::sole();
    expect($record->client)->toBe('lainos')
        ->and($record->instance_id)->toBe($instanceId)
        ->and($record->gate_exempt)->toBeFalse();
});

it('refuses to issue a key to an address that holds too little', function () {
    aiChain(AI_TENTH_PERCENT);
    $wallet = aiWallet();

    $challenge = $this->postJson('/api/ai/keys/nonce', ['address' => $wallet['address']])->json('message');

    $this->postJson('/api/ai/keys', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_holding');

    expect(AiApiKey::count())->toBe(0);
});

it('refuses a signature from another wallet, and answers a challenge only once', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();
    $stranger = aiWallet();

    $challenge = $this->postJson('/api/ai/keys/nonce', ['address' => $wallet['address']])->json('message');

    $this->postJson('/api/ai/keys', [
        'address' => $wallet['address'],
        'signature' => aiSign($stranger['pair'], $challenge),
    ])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    // The stranger's attempt consumed the nonce; the real holder must ask for
    // a new one rather than replaying this one.
    $this->postJson('/api/ai/keys', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
    ])->assertStatus(400)->assertJsonPath('error.code', 'challenge_expired');
});

it('completes a chat through the model’s provider and meters it', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();
    $key = aiIssueKey($wallet);

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        AI_GROQ_URL => Http::response(aiCompletion()),
    ]);

    $response = $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'are you there?']],
    ]);

    $response->assertOk()
        // The catalogue id answers, never the upstream one.
        ->assertJsonPath('model', 'lain-fast')
        ->assertJsonPath('provider', 'groq')
        ->assertJsonPath('choices.0.message.content', 'hello from the wired');

    Http::assertSent(fn (Request $request) => $request->url() === AI_GROQ_URL
        && $request->data()['model'] === 'llama-3.1-8b-instant'
        && $request->data()['max_tokens'] === 4096
        && $request->data()['stream'] === false);

    $usage = AiApiRequest::sole();
    expect($usage->model)->toBe('lain-fast')
        ->and($usage->served_model)->toBe('lain-fast')
        ->and($usage->provider)->toBe('groq')
        ->and($usage->prompt_tokens)->toBe(11)
        ->and($usage->completion_tokens)->toBe(7)
        ->and($usage->streamed)->toBeFalse();
});

it('caps max_tokens at the server limit instead of refusing the request', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());
    config()->set('ai.limits.max_output_tokens', 256);

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        AI_GROQ_URL => Http::response(aiCompletion()),
    ]);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'hi']],
        'max_tokens' => 100000,
    ])->assertOk();

    Http::assertSent(fn (Request $request) => $request->url() === AI_GROQ_URL
        && $request->data()['max_tokens'] === 256);
});

it('falls back to another model when the provider rate-limits it', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        AI_GROQ_URL => Http::response(['error' => ['message' => 'rate limited']], 429),
        AI_OPENROUTER_URL => Http::response(aiCompletion('meta-llama/llama-3.3-70b-instruct:free', 'the free one answered')),
    ]);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ])
        ->assertOk()
        ->assertJsonPath('model', 'lain-fast')
        // The substitution is reported rather than hidden.
        ->assertJsonPath('served_by', 'lain-free')
        ->assertJsonPath('provider', 'openrouter')
        ->assertJsonPath('choices.0.message.content', 'the free one answered');

    expect(AiApiRequest::sole()->served_model)->toBe('lain-free');
});

it('does not retry elsewhere when the provider rejected the request itself', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        AI_GROQ_URL => Http::response(['error' => ['message' => 'messages must alternate']], 400),
        AI_OPENROUTER_URL => Http::response(aiCompletion()),
    ]);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ])->assertStatus(400)->assertJsonPath('error.code', 'provider_rejected_request');

    Http::assertNotSent(fn (Request $request) => $request->url() === AI_OPENROUTER_URL);
});

it('streams chunks as server-sent events', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());

    $frames = implode('', [
        'data: '.json_encode(['id' => 'c1', 'model' => 'llama-3.1-8b-instant', 'choices' => [['delta' => ['content' => 'lain']]]])."\n\n",
        ": ping\n\n",
        'data: '.json_encode(['id' => 'c1', 'model' => 'llama-3.1-8b-instant', 'choices' => [['delta' => ['content' => ' speaks']]], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]])."\n\n",
        "data: [DONE]\n\n",
    ]);

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        AI_GROQ_URL => Http::response($frames, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $response = $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'speak']],
        'stream' => true,
    ], ['Accept' => 'text/event-stream']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $body = $response->streamedContent();

    expect($body)->toContain('"model":"lain-fast"')
        ->and($body)->toContain('lain')
        ->and($body)->toContain(' speaks')
        ->and($body)->toEndWith("data: [DONE]\n\n")
        // The keep-alive comment is upstream noise, not a chunk.
        ->and(substr_count($body, 'data: '))->toBe(3);

    $usage = AiApiRequest::sole();
    expect($usage->streamed)->toBeTrue()
        ->and($usage->completion_tokens)->toBe(2);
});

it('turns away a missing, unknown or revoked key', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();
    $key = aiIssueKey($wallet);

    $body = ['model' => 'lain-fast', 'messages' => [['role' => 'user', 'content' => 'hi']]];

    $this->postJson('/api/ai/v1/chat/completions', $body)
        ->assertUnauthorized()->assertJsonPath('error.type', 'authentication_error');

    $this->withToken('sk-cyb-nonsense')->postJson('/api/ai/v1/chat/completions', $body)
        ->assertUnauthorized();

    AiApiKey::sole()->forceFill(['revoked_at' => now()])->save();

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', $body)
        ->assertUnauthorized();
});

it('closes the API when the holding behind the key is sold', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();
    $key = aiIssueKey($wallet);

    // The same address, one block later, holding a tenth of a percent.
    Cache::flush();
    app()->instance('ai.balance', AI_TENTH_PERCENT);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'still there?']],
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_holding');

    Http::assertNotSent(fn (Request $request) => $request->url() === AI_GROQ_URL);
});

it('fails closed when the chain cannot be read', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());

    Cache::flush();
    app()->instance('ai.rpc_up', false);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'gate_unreadable');
});

it('lets a service key skip the gate but not the quota', function () {
    aiChain('0');
    config()->set('ai.limits.requests_per_day', 1);

    ['token' => $token] = app(AiKeyService::class)->issue('0x000000000000000000000000000000000000dead', 'lainos', true);

    Http::fake([AI_GROQ_URL => Http::response(aiCompletion())]);

    $body = ['model' => 'lain-fast', 'messages' => [['role' => 'user', 'content' => 'hi']]];

    $this->withToken($token)->postJson('/api/ai/v1/chat/completions', $body)->assertOk();

    $this->withToken($token)->postJson('/api/ai/v1/chat/completions', $body)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'daily_quota_exceeded');
});

it('holds one key to its per-minute limit', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());
    config()->set('ai.limits.requests_per_minute', 1);

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        AI_GROQ_URL => Http::response(aiCompletion()),
    ]);

    $body = ['model' => 'lain-fast', 'messages' => [['role' => 'user', 'content' => 'hi']]];

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', $body)->assertOk();

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', $body)
        ->assertStatus(429)
        ->assertJsonPath('error.type', 'rate_limit_error')
        ->assertHeader('Retry-After');
});

it('refuses an unknown model by naming the ones that exist', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'model_not_found')
        ->assertJsonFragment(['param' => 'model']);
});

it('validates the message list before spending anything upstream', function () {
    aiChain(AI_ONE_PERCENT);
    $key = aiIssueKey(aiWallet());

    Http::fake([
        AI_RPC_URL => Http::response(['result' => aiHex(AI_ONE_PERCENT)]),
        AI_GROQ_URL => Http::response(aiCompletion()),
    ]);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', ['model' => 'lain-fast', 'messages' => []])
        ->assertStatus(400)
        ->assertJsonPath('error.type', 'invalid_request_error');

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'wizard', 'content' => 'hi']],
    ])->assertStatus(400);

    config()->set('ai.limits.max_input_chars', 10);

    $this->withToken($key)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => str_repeat('a', 200)]],
    ])->assertStatus(400)->assertJsonPath('error.code', 'input_too_long');

    Http::assertNotSent(fn (Request $request) => $request->url() === AI_GROQ_URL);
});

it('reports what a key is and what it has spent', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();
    $key = aiIssueKey($wallet, 'laptop');

    $this->withToken($key)->getJson('/api/ai/v1/me')
        ->assertOk()
        ->assertJsonPath('address', $wallet['address'])
        ->assertJsonPath('key.name', 'laptop')
        ->assertJsonPath('gate.qualifies', true)
        ->assertJsonPath('usage.requests_today', 0);
});

it('lists and revokes a holder’s own keys, holding or not', function () {
    aiChain(AI_ONE_PERCENT);
    $wallet = aiWallet();
    aiIssueKey($wallet, 'first');

    $id = AiApiKey::sole()->id;

    // Sold out, and still able to see and kill what was left behind.
    app()->instance('ai.balance', AI_TENTH_PERCENT);
    Cache::flush();

    $challenge = $this->postJson('/api/ai/keys/nonce', ['address' => $wallet['address']])->json('message');
    $this->postJson('/api/ai/keys/list', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
    ])->assertOk()->assertJsonPath('keys.0.name', 'first');

    $challenge = $this->postJson('/api/ai/keys/nonce', ['address' => $wallet['address']])->json('message');
    $this->postJson('/api/ai/keys/revoke', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
        'id' => $id,
    ])->assertOk();

    expect(AiApiKey::find($id)->revoked())->toBeTrue();
});

it('will not issue more keys than one address may hold', function () {
    aiChain(AI_ONE_PERCENT);
    config()->set('ai.limits.keys_per_address', 1);
    $wallet = aiWallet();

    aiIssueKey($wallet);

    $challenge = $this->postJson('/api/ai/keys/nonce', ['address' => $wallet['address']])->json('message');

    $this->postJson('/api/ai/keys', [
        'address' => $wallet['address'],
        'signature' => aiSign($wallet['pair'], $challenge),
    ])->assertStatus(400)->assertJsonPath('error.code', 'key_limit_reached');
});
