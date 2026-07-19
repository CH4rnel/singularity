<?php

use App\Models\LainChatMessage;
use App\Models\LainChatSession;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
const LAIN_RPC_URL = 'https://rpc.cyberia.church';
const LAIN_TEST_WALLET = '0x433de5f1d2138e4700ef89c4ca50af9ec638f8b8';
const LAIN_TEST_TOTAL_SUPPLY = '1000000000000000000000';
const LAIN_TEST_TEN_PERCENT = '100000000000000000000';

function lainDecimalHex(string $decimal): string
{
    if (bccomp($decimal, '0') === 0) {
        return '0x0';
    }

    $hex = '';

    while (bccomp($decimal, '0') > 0) {
        $hex = dechex((int) bcmod($decimal, '16')).$hex;
        $decimal = bcdiv($decimal, '16', 0);
    }

    return '0x'.$hex;
}

/** Fake the Cyberia RPC holder reads (balanceOf vs totalSupply by selector). */
function fakeLainRpc(string $balance): void
{
    Http::fake([
        LAIN_RPC_URL => function (Request $request) use ($balance) {
            $data = (string) ($request->data()['params'][0]['data'] ?? '');

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => lainDecimalHex(
                    str_starts_with($data, '0x18160ddd') ? LAIN_TEST_TOTAL_SUPPLY : $balance,
                ),
            ]);
        },
    ]);
}

function fakeOpenRouter(string $reply, string $model = 'meta-llama/llama-3.3-70b-instruct:free'): void
{
    Http::fake([
        OPENROUTER_URL => Http::response([
            'model' => $model,
            'choices' => [['message' => ['content' => $reply]]],
        ]),
    ]);
}

function qualifyingUser(): User
{
    fakeLainRpc(LAIN_TEST_TEN_PERCENT);

    return User::factory()->create(['wallet_address' => LAIN_TEST_WALLET]);
}

function sessionFor(User $user, string $title = 'Conversation'): LainChatSession
{
    return $user->lainChatSessions()->create(['title' => $title]);
}

beforeEach(function () {
    config()->set('services.lain.openrouter_api_key', 'test-key');
    config()->set('services.lain.model', 'openrouter/free');
    config()->set('services.ethereum.rpc_url', LAIN_RPC_URL);
    config()->set('services.lain.token_address', '0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b');
    config()->set('services.lain.minimum_share_bps', 1000);
});

it('serves the page to guests with a locked gate', function () {
    $this->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LainChat')
            ->where('enabled', true)
            ->where('gate.state', 'guest')
            ->where('gate.qualifies', false)
            ->where('sessions', [])
            ->where('activeSessionId', null)
            ->where('messages', []));
});

it('opens the gate for a wallet holding ten percent of LAIN', function () {
    $this->actingAs(qualifyingUser())
        ->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LainChat')
            ->where('gate.state', 'checked')
            ->where('gate.qualifies', true)
            ->where('gate.balance', LAIN_TEST_TEN_PERCENT)
            ->where('gate.shareBps', 1000));
});

it('lists sessions newest-first and preloads the latest transcript', function () {
    $user = qualifyingUser();
    $old = sessionFor($user, 'old thread');
    $old->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'old question']);
    $recent = sessionFor($user, 'recent thread');
    $recent->messages()->create(['user_id' => $user->id, 'role' => 'lain', 'content' => 'recent reply']);

    $this->actingAs($user)
        ->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 2)
            ->where('sessions.0.title', 'recent thread')
            ->where('sessions.1.title', 'old thread')
            ->where('activeSessionId', $recent->id)
            ->has('messages', 1)
            ->where('messages.0.text', 'recent reply'));
});

it('still shows transcripts to holders who dropped below the threshold', function () {
    fakeLainRpc('99999999999999999999');
    $user = User::factory()->create(['wallet_address' => LAIN_TEST_WALLET]);
    $session = sessionFor($user);
    $session->messages()->create(['user_id' => $user->id, 'role' => 'lain', 'content' => 'old reply']);

    $this->actingAs($user)
        ->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('gate.qualifies', false)
            ->has('sessions', 1)
            ->has('messages', 1));
});

it('returns a session transcript and hides foreign sessions', function () {
    $user = qualifyingUser();
    $session = sessionFor($user, 'mine');
    $session->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'hello']);
    $stranger = User::factory()->create();
    $foreign = sessionFor($stranger, 'not yours');

    $this->actingAs($user)
        ->getJson("/api/lain/sessions/{$session->id}")
        ->assertOk()
        ->assertJsonPath('session.title', 'mine')
        ->assertJsonPath('messages.0.text', 'hello');

    $this->actingAs($user)
        ->getJson("/api/lain/sessions/{$foreign->id}")
        ->assertNotFound();
});

it('rejects chat and session reads from guests', function () {
    $this->postJson('/api/lain/chat', ['text' => 'hi'])->assertUnauthorized();
    $this->getJson('/api/lain/sessions/1')->assertUnauthorized();
});

it('refuses chat when the wallet holds less than ten percent of LAIN', function () {
    fakeLainRpc('99999999999999999999');
    $user = User::factory()->create(['wallet_address' => LAIN_TEST_WALLET]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertForbidden()
        ->assertJsonPath('gate.qualifies', false);

    Http::assertNotSent(fn (Request $request) => $request->url() === OPENROUTER_URL);
    expect(LainChatMessage::where('user_id', $user->id)->count())->toBe(0);
});

it('locks the gate for accounts without an EVM wallet', function () {
    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hi'])
        ->assertForbidden()
        ->assertJsonPath('gate.state', 'no_wallet');
});

it('reports a holder-check RPC failure as temporary', function () {
    Http::fake([LAIN_RPC_URL => Http::response([], 500)]);
    $user = User::factory()->create(['wallet_address' => LAIN_TEST_WALLET]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertServiceUnavailable()
        ->assertJsonPath('gate.state', 'error');

    Http::assertNotSent(fn (Request $request) => $request->url() === OPENROUTER_URL);
});

it('creates a session lazily on the first answered message', function () {
    $user = qualifyingUser();
    fakeOpenRouter('<think>secret chain of thought</think>present day, present time.');

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello, lain'])
        ->assertOk()
        ->assertJsonPath('text', 'present day, present time.')
        ->assertJsonPath('session.title', 'hello, lain');

    $session = LainChatSession::where('user_id', $user->id)->sole();
    $rows = $session->messages()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->role)->toBe('user')
        ->and($rows[0]->content)->toBe('hello, lain')
        ->and($rows[1]->role)->toBe('lain')
        ->and($rows[1]->content)->toBe('present day, present time.')
        ->and($rows[1]->model)->toBe('meta-llama/llama-3.3-70b-instruct:free');

    Http::assertSent(function (Request $request) {
        if ($request->url() !== OPENROUTER_URL) {
            return false;
        }
        $messages = $request['messages'];

        return $messages[0]['role'] === 'system'
            && str_contains($messages[0]['content'], 'LainOS')
            && str_contains($messages[0]['content'], '49406')
            && str_contains($messages[0]['content'], 'NO tools')
            && end($messages)['content'] === 'hello, lain';
    });
});

it('keeps model context scoped to the addressed session', function () {
    $user = qualifyingUser();
    $other = sessionFor($user, 'other thread');
    $other->messages()->create(['user_id' => $user->id, 'role' => 'user', 'content' => 'other-session secret']);
    $session = sessionFor($user, 'this thread');
    $session->messages()->createMany([
        ['user_id' => $user->id, 'role' => 'user', 'content' => 'earlier question'],
        ['user_id' => $user->id, 'role' => 'lain', 'content' => 'earlier reply'],
    ]);
    fakeOpenRouter('listening.');

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'still there?', 'session_id' => $session->id])
        ->assertOk()
        ->assertJsonPath('session.id', $session->id);

    Http::assertSent(function (Request $request) {
        if ($request->url() !== OPENROUTER_URL) {
            return false;
        }
        $contents = array_column($request['messages'], 'content');

        return in_array('earlier question', $contents, true)
            && in_array('earlier reply', $contents, true)
            && ! in_array('other-session secret', $contents, true);
    });

    expect($session->messages()->count())->toBe(4)
        ->and($other->messages()->count())->toBe(1);
});

it('rejects chatting into a foreign session', function () {
    $user = qualifyingUser();
    $stranger = User::factory()->create();
    $foreign = sessionFor($stranger);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hi', 'session_id' => $foreign->id])
        ->assertNotFound();

    Http::assertNotSent(fn (Request $request) => $request->url() === OPENROUTER_URL);
    expect($foreign->messages()->count())->toBe(0);
});

it('retries once when the model ships an empty reply', function () {
    $user = qualifyingUser();
    Http::fakeSequence(OPENROUTER_URL)
        ->push(['model' => 'free', 'choices' => [['message' => ['content' => '<think>burned the budget</think>']]]])
        ->push(['model' => 'free', 'choices' => [['message' => ['content' => 'here now.']]]]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertOk()
        ->assertJsonPath('text', 'here now.');
});

it('falls back to the free router when the pinned model is rate-limited', function () {
    config()->set('services.lain.model', 'qwen/qwen3-next-80b-a3b-instruct:free');
    config()->set('services.lain.fallback_model', 'openrouter/free');
    $user = qualifyingUser();
    Http::fakeSequence(OPENROUTER_URL)
        ->push(['error' => ['message' => 'rate-limited upstream']], 429)
        ->push(['model' => 'openai/gpt-oss-20b:free', 'choices' => [['message' => ['content' => 'still here.']]]]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertOk()
        ->assertJsonPath('text', 'still here.');

    Http::assertSent(fn (Request $request) => $request->url() === OPENROUTER_URL
        && $request['model'] === 'openrouter/free');
});

it('persists neither the turn nor a session when the model fails', function () {
    $user = qualifyingUser();
    Http::fake([OPENROUTER_URL => Http::response(['error' => ['message' => 'rate limited']], 429)]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertServiceUnavailable();

    expect(LainChatMessage::where('user_id', $user->id)->count())->toBe(0)
        ->and(LainChatSession::where('user_id', $user->id)->count())->toBe(0);
});
