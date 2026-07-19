<?php

use App\Models\LainChatMessage;
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

it('locks the gate for a wallet below the threshold and hides history', function () {
    fakeLainRpc('99999999999999999999');
    $user = User::factory()->create(['wallet_address' => LAIN_TEST_WALLET]);
    $user->lainChatMessages()->create(['role' => 'lain', 'content' => 'old reply']);

    $this->actingAs($user)
        ->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('gate.state', 'checked')
            ->where('gate.qualifies', false)
            ->where('messages', []));
});

it('locks the gate for accounts without an EVM wallet', function () {
    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)
        ->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('gate.state', 'no_wallet'));

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hi'])
        ->assertForbidden()
        ->assertJsonPath('gate.state', 'no_wallet');
});

it('rejects chat from guests', function () {
    $this->postJson('/api/lain/chat', ['text' => 'hi'])->assertUnauthorized();
    $this->postJson('/api/lain/reset')->assertUnauthorized();
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

it('reports a holder-check RPC failure as temporary', function () {
    Http::fake([LAIN_RPC_URL => Http::response([], 500)]);
    $user = User::factory()->create(['wallet_address' => LAIN_TEST_WALLET]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertServiceUnavailable()
        ->assertJsonPath('gate.state', 'error');

    Http::assertNotSent(fn (Request $request) => $request->url() === OPENROUTER_URL);
});

it('answers a qualifying holder and persists both turns', function () {
    $user = qualifyingUser();
    fakeOpenRouter('<think>secret chain of thought</think>present day, present time.');

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello, lain'])
        ->assertOk()
        ->assertJsonPath('text', 'present day, present time.');

    $rows = LainChatMessage::where('user_id', $user->id)->orderBy('id')->get();
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

it('replays prior conversation as context but not across a reset', function () {
    $user = qualifyingUser();
    $user->lainChatMessages()->createMany([
        ['role' => 'user', 'content' => 'before reset'],
        ['role' => 'lain', 'content' => 'old reply'],
        ['role' => 'reset', 'content' => ''],
        ['role' => 'user', 'content' => 'after reset'],
        ['role' => 'lain', 'content' => 'fresh reply'],
    ]);
    fakeOpenRouter('listening.');

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'still there?'])
        ->assertOk();

    Http::assertSent(function (Request $request) {
        if ($request->url() !== OPENROUTER_URL) {
            return false;
        }
        $contents = array_column($request['messages'], 'content');

        return in_array('after reset', $contents, true)
            && in_array('fresh reply', $contents, true)
            && ! in_array('before reset', $contents, true)
            && ! in_array('old reply', $contents, true);
    });
});

it('marks a reset boundary without deleting the transcript', function () {
    $user = qualifyingUser();
    $user->lainChatMessages()->createMany([
        ['role' => 'user', 'content' => 'hello'],
        ['role' => 'lain', 'content' => 'hi'],
    ]);

    $this->actingAs($user)->postJson('/api/lain/reset')->assertOk();

    expect(LainChatMessage::where('user_id', $user->id)->count())->toBe(3)
        ->and(LainChatMessage::where('user_id', $user->id)->where('role', 'reset')->count())->toBe(1);

    // A second reset on an already-empty conversation adds nothing.
    $this->actingAs($user)->postJson('/api/lain/reset')->assertOk();
    expect(LainChatMessage::where('user_id', $user->id)->count())->toBe(3);
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

it('does not persist the user turn when the model fails', function () {
    $user = qualifyingUser();
    Http::fake([OPENROUTER_URL => Http::response(['error' => ['message' => 'rate limited']], 429)]);

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertServiceUnavailable();

    expect(LainChatMessage::where('user_id', $user->id)->count())->toBe(0);
});
