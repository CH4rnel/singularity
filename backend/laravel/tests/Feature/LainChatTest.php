<?php

use App\Models\LainChatMessage;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

function fakeOpenRouter(string $reply, string $model = 'meta-llama/llama-3.3-70b-instruct:free'): void
{
    Http::fake([
        OPENROUTER_URL => Http::response([
            'model' => $model,
            'choices' => [['message' => ['content' => $reply]]],
        ]),
    ]);
}

beforeEach(function () {
    config()->set('services.lain.openrouter_api_key', 'test-key');
    config()->set('services.lain.model', 'openrouter/free');
});

it('serves the Lain chat page to guests without history', function () {
    $this->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LainChat')
            ->where('enabled', true)
            ->where('messages', []));
});

it('reports chat as disabled without an OpenRouter key', function () {
    config()->set('services.lain.openrouter_api_key', null);

    $this->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LainChat')
            ->where('enabled', false));
});

it('rejects chat from guests', function () {
    $this->postJson('/api/lain/chat', ['text' => 'hi'])->assertUnauthorized();
    $this->postJson('/api/lain/reset')->assertUnauthorized();
});

it('answers a signed-in user and persists both turns', function () {
    fakeOpenRouter('<think>secret chain of thought</think>present day, present time.');
    $user = User::factory()->create();

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
        $messages = $request['messages'];

        return $request->url() === OPENROUTER_URL
            && $messages[0]['role'] === 'system'
            && str_contains($messages[0]['content'], 'LainOS')
            && str_contains($messages[0]['content'], '49406')
            && str_contains($messages[0]['content'], 'NO tools')
            && end($messages)['content'] === 'hello, lain';
    });
});

it('replays prior conversation as context but not across a reset', function () {
    $user = User::factory()->create();
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
        $contents = array_column($request['messages'], 'content');

        return in_array('after reset', $contents, true)
            && in_array('fresh reply', $contents, true)
            && ! in_array('before reset', $contents, true)
            && ! in_array('old reply', $contents, true);
    });
});

it('shows only the current conversation on the page', function () {
    $user = User::factory()->create();
    $user->lainChatMessages()->createMany([
        ['role' => 'user', 'content' => 'before reset'],
        ['role' => 'reset', 'content' => ''],
        ['role' => 'lain', 'content' => 'fresh reply'],
    ]);

    $this->actingAs($user)
        ->get('/lain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LainChat')
            ->has('messages', 1)
            ->where('messages.0.text', 'fresh reply')
            ->where('messages.0.role', 'lain'));
});

it('marks a reset boundary without deleting the transcript', function () {
    $user = User::factory()->create();
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
    Http::fakeSequence(OPENROUTER_URL)
        ->push(['model' => 'free', 'choices' => [['message' => ['content' => '<think>burned the budget</think>']]]])
        ->push(['model' => 'free', 'choices' => [['message' => ['content' => 'here now.']]]]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertOk()
        ->assertJsonPath('text', 'here now.');

    Http::assertSentCount(2);
});

it('falls back to the free router when the pinned model is rate-limited', function () {
    config()->set('services.lain.model', 'qwen/qwen3-next-80b-a3b-instruct:free');
    config()->set('services.lain.fallback_model', 'openrouter/free');
    Http::fakeSequence(OPENROUTER_URL)
        ->push(['error' => ['message' => 'rate-limited upstream']], 429)
        ->push(['model' => 'openai/gpt-oss-20b:free', 'choices' => [['message' => ['content' => 'still here.']]]]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertOk()
        ->assertJsonPath('text', 'still here.');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['model'] === 'openrouter/free');
});

it('does not persist the user turn when the model fails', function () {
    Http::fake([OPENROUTER_URL => Http::response(['error' => ['message' => 'rate limited']], 429)]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/lain/chat', ['text' => 'hello?'])
        ->assertServiceUnavailable();

    expect(LainChatMessage::where('user_id', $user->id)->count())->toBe(0);
});
