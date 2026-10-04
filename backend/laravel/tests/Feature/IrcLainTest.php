<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Lain on irc.cyberia.church: the bot forwards a line, this answers it with the
 * tool-less persona. The token gate is the whole difference between that and
 * an anonymous pipe into a paid model.
 */
const IRC_OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

beforeEach(function () {
    config([
        'services.lain.irc_token' => 'irc-secret',
        'services.lain.openrouter_api_key' => 'test-key',
        'services.lain.model' => 'test/model',
        'services.lain.fallback_model' => 'test/model',
    ]);
});

function ircOpenRouter(string $reply = 'hi. the wired is quiet tonight.'): void
{
    Http::fake([IRC_OPENROUTER_URL => Http::response([
        'model' => 'test/model',
        'choices' => [['message' => ['content' => $reply]]],
    ])]);
}

it('does not exist without a configured token', function () {
    config(['services.lain.irc_token' => null]);
    ircOpenRouter();

    $this->withHeader('X-Irc-Token', '')
        ->postJson('/api/irc/lain', ['target' => '#cyberia', 'nick' => 'neo', 'text' => 'lain: hi'])
        ->assertNotFound();

    Http::assertNothingSent();
});

it('does not exist for a wrong token', function () {
    ircOpenRouter();

    $this->withHeader('X-Irc-Token', 'guess')
        ->postJson('/api/irc/lain', ['target' => '#cyberia', 'nick' => 'neo', 'text' => 'hi'])
        ->assertNotFound();

    Http::assertNothingSent();
});

it('answers a channel line and keeps the voices apart', function () {
    ircOpenRouter();

    $this->withHeader('X-Irc-Token', 'irc-secret')
        ->postJson('/api/irc/lain', [
            'target' => '#cyberia',
            'nick' => 'neo',
            'text' => 'what is cyberia?',
            'history' => [
                ['nick' => 'trinity', 'text' => 'evening all'],
                ['nick' => 'lain', 'text' => 'hello, trinity.'],
            ],
        ])
        ->assertOk()
        ->assertJson(['text' => 'hi. the wired is quiet tonight.', 'model' => 'test/model']);

    Http::assertSent(function (Request $request) {
        $messages = $request->data()['messages'];

        return str_contains($messages[0]['content'], 'the IRC channel #cyberia')
            && str_contains($messages[0]['content'], 'no markdown')
            && $messages[1] === ['role' => 'user', 'content' => '<trinity> evening all']
            && $messages[2] === ['role' => 'assistant', 'content' => 'hello, trinity.']
            && $messages[3] === ['role' => 'user', 'content' => '<neo> what is cyberia?'];
    });
});

it('tells a private query apart from a channel', function () {
    ircOpenRouter();

    $this->withHeader('X-Irc-Token', 'irc-secret')
        ->postJson('/api/irc/lain', ['target' => 'neo', 'nick' => 'neo', 'text' => 'hi'])
        ->assertOk();

    Http::assertSent(fn (Request $request) => str_contains(
        $request->data()['messages'][0]['content'],
        'a private IRC query on irc.cyberia.church',
    ));
});

it('says unreachable instead of failing when the model is down', function () {
    Http::fake([IRC_OPENROUTER_URL => Http::response([], 503)]);

    $this->withHeader('X-Irc-Token', 'irc-secret')
        ->postJson('/api/irc/lain', ['target' => '#cyberia', 'nick' => 'neo', 'text' => 'hi'])
        ->assertStatus(503);
});
