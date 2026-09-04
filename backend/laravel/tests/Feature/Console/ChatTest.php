<?php

use App\Models\CrmChatFile;
use App\Models\CrmChatMessage;
use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\ServiceIncident;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config()->set('crm.admin_wallets', [
        '0x00000000000000000000000000000000000000aa',
        '0x00000000000000000000000000000000000000bb',
    ]);
    config()->set('crm.admin_user_ids', []);
    config()->set('services.lainos.url', null);
    config()->set('services.lain.openrouter_api_key', null);
    Storage::fake('local');
    Cache::flush();
});

function chatOperator(
    string $name = 'lain',
    string $wallet = '0x00000000000000000000000000000000000000aa',
): User {
    return User::factory()->create([
        'name' => $name,
        'wallet_address' => $wallet,
    ]);
}

it('is a 404 for a signed-in stranger, not a 403', function () {
    // The console is not discoverable by trying its address: a 403 would
    // confirm the room exists.
    $this->actingAs(User::factory()->create())->get('/crm/chat')->assertNotFound();
});

it('sends a guest to the sign-in page', function () {
    $this->get('/crm/chat')->assertRedirect('/login');
});

it('keeps a message and the file it arrived with together', function () {
    $operator = chatOperator();

    $this->actingAs($operator)
        ->post('/crm/chat', [
            'body' => 'лог релеера за ночь',
            'files' => [UploadedFile::fake()->create('bridge-relay.log', 12, 'text/plain')],
        ])
        ->assertRedirect();

    $message = CrmChatMessage::query()->sole();

    expect($message->body)->toBe('лог релеера за ночь')
        ->and($message->author)->toBe(CrmChatMessage::AUTHOR_OPERATOR)
        ->and($message->calls_lainos)->toBeFalse();

    $file = CrmChatFile::query()->sole();

    expect($file->crm_chat_message_id)->toBe($message->id)
        ->and($file->name)->toBe('bridge-relay.log')
        // The segment is decided once, on the way in.
        ->and($file->kind)->toBe('log');

    Storage::disk('local')->assertExists($file->path);
});

it('refuses a runnable file outright', function () {
    $operator = chatOperator();

    $this->actingAs($operator)
        ->post('/crm/chat', [
            'body' => 'вот скрипт',
            'files' => [UploadedFile::fake()->create('deploy.sh', 2, 'text/plain')],
        ])
        ->assertSessionHasErrors('files.0');

    expect(CrmChatMessage::query()->count())->toBe(0)
        ->and(CrmChatFile::query()->count())->toBe(0);
});

it('refuses an empty line with nothing attached', function () {
    $this->actingAs(chatOperator())
        ->post('/crm/chat', ['body' => '   '])
        ->assertSessionHasErrors('body');

    expect(CrmChatMessage::query()->count())->toBe(0);
});

it('hands a file back only inside the console', function () {
    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', [
        'body' => 'конфиги',
        'files' => [UploadedFile::fake()->create('nginx.conf', 1, 'text/plain')],
    ]);

    $file = CrmChatFile::query()->sole();

    $this->actingAs($operator)->get("/crm/chat/files/{$file->id}")->assertOk();

    // The private disk has no URL of its own, so this route is the only door
    // — and it is a 404 for anyone who is not an operator.
    $this->actingAs(User::factory()->create())
        ->get("/crm/chat/files/{$file->id}")
        ->assertNotFound();
});

it('attaches the person a line names', function () {
    $operator = chatOperator();
    $contact = CrmContact::factory()->create(['name' => 'Nakamoto Ghost']);

    $this->actingAs($operator)->post('/crm/chat', [
        'body' => 'кит вернулся #Nakamoto',
    ]);

    expect(CrmChatMessage::query()->sole()->crm_contact_id)->toBe($contact->id);
});

it('turns a line into a task and keeps its number on the line', function () {
    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', [
        'body' => 'развести ключ релеера и минтера !завтра',
    ]);

    $message = CrmChatMessage::query()->sole();

    $this->actingAs($operator)->post("/crm/chat/{$message->id}/task")->assertRedirect();

    $task = CrmTask::query()->sole();

    expect($task->title)->toBe('развести ключ релеера и минтера')
        ->and($task->due_at)->not->toBeNull()
        ->and($message->fresh()->crm_task_id)->toBe($task->id);

    // Pressing it twice does not make a second task.
    $this->actingAs($operator)->post("/crm/chat/{$message->id}/task");

    expect(CrmTask::query()->count())->toBe(1);
});

it('marks a line that calls LainOS, and says so when nothing can answer', function () {
    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', ['body' => '@lainos что в логе?']);

    $message = CrmChatMessage::query()->sole();

    expect($message->calls_lainos)->toBeTrue()
        // No daemon address and no model key: the room says that rather than
        // promising an answer that cannot come.
        ->and($message->lainos_state)->toBe(CrmChatMessage::LAINOS_FAILED)
        ->and($message->lainos_note)->toBe('disabled');
});

it('writes the daemon’s answer into the room, stamped with who answered', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    Http::fake([
        '127.0.0.1:7777/provider' => Http::response([
            'provider' => [
                'kind' => 'claude',
                'name' => 'claude+openrouter',
                'model' => 'claude-sonnet-4-5',
                'envKind' => 'codex',
                'overridden' => true,
            ],
            'choices' => [['name' => 'claude', 'kind' => 'claude', 'desc' => 'Claude CLI']],
        ], 200),
        // The turn names the model that produced it; the probe above only
        // knows what the daemon was on a moment earlier.
        '127.0.0.1:7777/chat' => Http::response([
            'text' => 'все три с одного адреса',
            'model' => 'codex/gpt-5.6-sol',
        ], 200),
    ]);

    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', ['body' => '@lainos что общего у падений?']);

    $call = CrmChatMessage::query()->sole();

    expect($call->lainos_state)->toBe(CrmChatMessage::LAINOS_AWAITING);

    $this->actingAs($operator)
        ->post("/crm/chat/{$call->id}/answer")
        ->assertOk()
        ->assertJson(['state' => CrmChatMessage::LAINOS_ANSWERED]);

    $answer = CrmChatMessage::query()->where('author', CrmChatMessage::AUTHOR_LAINOS)->sole();

    expect($answer->body)->toBe('все три с одного адреса')
        ->and($answer->user_id)->toBeNull()
        ->and($answer->meta['backend'])->toBe('daemon')
        // The model is read off the daemon rather than guessed: "which model
        // answered" is the first question asked of any answer here.
        ->and($answer->meta['model'])->toBe('codex/gpt-5.6-sol')
        ->and($answer->meta['model_source'])->toBe('turn')
        ->and($answer->meta['provider'])->toBe('claude')
        ->and($answer->meta['ensemble'])->toBe('claude+openrouter')
        ->and($answer->meta['overridden'])->toBeTrue()
        ->and($answer->meta['ms'])->toBeInt()
        ->and($answer->meta['context']['messages'])->toBe(1)
        ->and($call->fresh()->lainos_state)->toBe(CrmChatMessage::LAINOS_ANSWERED);
});

it('shows which model the daemon is on, and says so when it will not tell', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    // One reading per request, and the second one fails: a second Http::fake()
    // would merge rather than replace, so the change of weather is a sequence.
    Http::fake([
        '127.0.0.1:7777/provider' => Http::sequence()
            ->push([
                'provider' => [
                    'kind' => 'codex',
                    'name' => 'codex',
                    'model' => 'gpt-5-codex',
                    'envKind' => 'codex',
                    'overridden' => false,
                ],
                'choices' => [
                    ['name' => 'claude', 'kind' => 'claude', 'desc' => 'Claude CLI'],
                    ['name' => 'codex', 'kind' => 'codex', 'desc' => 'Codex CLI'],
                ],
            ], 200)
            ->push('', 500),
    ]);

    $this->actingAs(chatOperator())
        ->get('/crm/chat')
        ->assertInertia(fn (Assert $page) => $page
            ->where('lainos.probe', 'ok')
            ->where('lainos.provider.model', 'gpt-5-codex')
            ->has('lainos.choices', 2)
            ->etc());

    Cache::flush();

    // Unreadable is its own state: the daemon may be answering perfectly well
    // and simply not have said what with.
    $this->actingAs(chatOperator('netrunner', '0x00000000000000000000000000000000000000bb'))
        ->get('/crm/chat')
        ->assertInertia(fn (Assert $page) => $page
            ->where('lainos.probe', 'unreadable')
            ->where('lainos.provider', null)
            ->etc());
});

it('falls back to the probe when a turn does not name its model', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    Http::fake([
        '127.0.0.1:7777/provider' => Http::response(['provider' => [
            'kind' => 'claude', 'name' => 'claude', 'model' => 'claude-sonnet-4-5',
            'envKind' => 'claude', 'overridden' => false,
        ]], 200),
        '127.0.0.1:7777/chat' => Http::response(['text' => 'без провенанса'], 200),
    ]);

    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', ['body' => '@lainos ?']);
    $call = CrmChatMessage::query()->sole();
    $this->actingAs($operator)->post("/crm/chat/{$call->id}/answer")->assertOk();

    $answer = CrmChatMessage::query()->where('author', CrmChatMessage::AUTHOR_LAINOS)->sole();

    expect($answer->meta['model'])->toBe('claude-sonnet-4-5')
        // Named as the weaker source it is, rather than passed off as
        // provenance.
        ->and($answer->meta['model_source'])->toBe('probe');
});

it('records what was tried when nothing answered', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    Http::fake([
        '127.0.0.1:7777/provider' => Http::response(['provider' => [
            'kind' => 'codex', 'name' => 'codex', 'model' => 'gpt-5-codex',
            'envKind' => 'codex', 'overridden' => false,
        ]], 200),
        '127.0.0.1:7777/chat' => Http::response('', 503),
    ]);

    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', ['body' => '@lainos почему упал воркер?']);

    $call = CrmChatMessage::query()->sole();

    $this->actingAs($operator)->post("/crm/chat/{$call->id}/answer")->assertOk();

    $attempts = $call->fresh()->meta['attempts'];

    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]['backend'])->toBe('daemon')
        ->and($attempts[0]['outcome'])->toBe('http_503')
        ->and($attempts[0]['model'])->toBe('gpt-5-codex');
});

it('switches the daemon’s provider, and repeats its refusal verbatim', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    Http::fake([
        '127.0.0.1:7777/provider' => Http::sequence()
            ->push(['provider' => [
                'kind' => 'claude', 'name' => 'claude', 'model' => 'claude-sonnet-4-5',
                'envKind' => 'codex', 'overridden' => true,
            ]], 200)
            ->push(['provider' => [
                'kind' => 'claude', 'name' => 'claude', 'model' => 'claude-sonnet-4-5',
                'envKind' => 'codex', 'overridden' => true,
            ]], 200)
            ->push(['error' => 'provider "opencode" is unavailable (missing API key or CLI?)'], 409),
    ]);

    $operator = chatOperator();

    $this->actingAs($operator)
        ->post('/crm/chat/lainos/provider', ['provider' => 'claude'])
        ->assertOk()
        ->assertJson(['ok' => true, 'provider' => ['kind' => 'claude']]);

    $this->actingAs($operator)
        ->post('/crm/chat/lainos/provider', ['provider' => 'opencode'])
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'provider "opencode" is unavailable (missing API key or CLI?)']);
});

it('reports an unreachable daemon instead of inventing an answer', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    Http::fake([
        '127.0.0.1:7777/provider' => Http::response('', 500),
        '127.0.0.1:7777/chat' => Http::response('', 500),
    ]);

    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', ['body' => '@lainos набросай письмо']);

    $call = CrmChatMessage::query()->sole();

    $this->actingAs($operator)
        ->post("/crm/chat/{$call->id}/answer")
        ->assertOk()
        ->assertJson(['state' => CrmChatMessage::LAINOS_FAILED, 'note' => 'unreachable']);

    expect(CrmChatMessage::query()->where('author', CrmChatMessage::AUTHOR_LAINOS)->count())->toBe(0);
});

it('does not answer a line that never called', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');

    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', ['body' => 'а бак газа долили?']);

    $message = CrmChatMessage::query()->sole();

    $this->actingAs($operator)->post("/crm/chat/{$message->id}/answer")->assertNotFound();
});

it('counts what this operator has not read, and never their own lines', function () {
    config()->set('crm.admin_user_ids', []);
    $lain = chatOperator('lain');
    $netrunner = chatOperator('netrunner', '0x00000000000000000000000000000000000000bb');

    $this->actingAs($lain)->post('/crm/chat', ['body' => 'своё сообщение']);

    $this->actingAs($lain)
        ->get('/crm/tasks')
        ->assertInertia(fn (Assert $page) => $page->where('console.counts.chat', 0));

    $this->actingAs($netrunner)->post('/crm/chat', ['body' => 'чужое сообщение']);

    $this->actingAs($lain)
        ->get('/crm/tasks')
        ->assertInertia(fn (Assert $page) => $page->where('console.counts.chat', 1));

    // Opening the room is reading it.
    $this->actingAs($lain)->get('/crm/chat')->assertOk();

    $this->actingAs($lain)
        ->get('/crm/tasks')
        ->assertInertia(fn (Assert $page) => $page->where('console.counts.chat', 0));
});

it('lets an operator take their own line back, with its files', function () {
    $operator = chatOperator();
    $other = chatOperator('netrunner', '0x00000000000000000000000000000000000000bb');

    $this->actingAs($operator)->post('/crm/chat', [
        'body' => 'не то',
        'files' => [UploadedFile::fake()->create('oops.txt', 1, 'text/plain')],
    ]);

    $message = CrmChatMessage::query()->sole();
    $path = CrmChatFile::query()->sole()->path;

    $this->actingAs($other)->delete("/crm/chat/{$message->id}")->assertForbidden();

    $this->actingAs($operator)->delete("/crm/chat/{$message->id}")->assertRedirect();

    expect(CrmChatMessage::query()->count())->toBe(0)
        ->and(CrmChatFile::query()->count())->toBe(0);

    Storage::disk('local')->assertMissing($path);
});

it('reads the room as a pile of files, by segment', function () {
    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', [
        'body' => 'скрин к совещанию',
        'files' => [UploadedFile::fake()->image('d7.png')],
    ]);
    $this->actingAs($operator)->post('/crm/chat', [
        'body' => 'лог за ночь',
        'files' => [UploadedFile::fake()->create('queue.log', 3, 'text/plain')],
    ]);

    $this->actingAs($operator)
        ->get('/crm/chat/files?segment=image')
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/ChatFiles')
            ->where('segment', 'image')
            ->has('files', 1)
            ->where('files.0.name', 'd7.png')
            // The table can say what a file was for, which is the whole
            // reason there is no folder anywhere in this feature.
            ->where('files.0.reason', 'скрин к совещанию'));
});

it('serves the room itself with its people and its limits', function () {
    $operator = chatOperator();

    $this->actingAs($operator)->post('/crm/chat', ['body' => 'здесь']);

    $this->actingAs($operator)
        ->get('/crm/chat')
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Chat')
            ->has('messages', 1)
            ->where('messages.0.mine', true)
            // LainOS is a participant with a stated backend, even when there
            // is none: "not wired up" is a state and not an absence.
            ->where('people.1.kind', 'lainos')
            ->where('lainos.backend', null)
            ->where('limits.maxMb', 25)
            ->etc());
});

/*
 * What LainOS is actually told.
 *
 * The room's first version handed it twenty chat lines and then instructed it
 * not to invent numbers — two correct halves that together made a
 * correspondent which could only ever answer "посмотри в линзе". The state of
 * the project now goes up with the question, composed from the same caches the
 * lenses render, and the two backends are told different things about it: the
 * daemon that it is a starting point, the persona that it is the end of the
 * line.
 */
it('hands LainOS the state of the project, not just the room', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    config()->set('wallet.sponsor.station', null);

    ServiceIncident::create([
        'service' => 'cyberia-rpc',
        'status' => 'down',
        'reason' => 'stale-head',
        'started_at' => now()->subHour(),
    ]);

    Http::fake([
        '127.0.0.1:7777/provider' => Http::response(['provider' => null], 500),
        '127.0.0.1:7777/chat' => Http::response(['text' => 'нода не пилит блоки'], 200),
    ]);

    $operator = chatOperator();
    $this->actingAs($operator)->post('/crm/chat', ['body' => '@lainos что у нас происходит?']);
    $call = CrmChatMessage::query()->sole();

    $this->actingAs($operator)->post("/crm/chat/{$call->id}/answer")->assertOk();

    Http::assertSent(function ($request) {
        $sent = str_contains($request->url(), '/chat') ? (string) $request['text'] : '';

        return str_contains($sent, '# Состояние проекта')
            && str_contains($sent, 'Требуют человека')
            && str_contains($sent, 'stale-head')
            // The daemon has tools; telling it otherwise is what made the one
            // correspondent that can go and look behave like the one that cannot.
            && str_contains($sent, 'инструменты у тебя есть');
    });

    $answer = CrmChatMessage::query()->where('author', CrmChatMessage::AUTHOR_LAINOS)->sole();

    // The stamp records that the answer had the briefing, and when it was
    // composed: an answer given without one is a different answer.
    expect($answer->meta['context']['briefing'])->toBeString();
});

it('says on the answer when it went out without the state of the project', function () {
    config()->set('services.lainos.url', 'http://127.0.0.1:7777');
    config()->set('crm.chat.lainos.briefing', false);

    Http::fake([
        '127.0.0.1:7777/provider' => Http::response(['provider' => null], 500),
        '127.0.0.1:7777/chat' => Http::response(['text' => 'не знаю'], 200),
    ]);

    $operator = chatOperator();
    $this->actingAs($operator)->post('/crm/chat', ['body' => '@lainos как дела?']);
    $call = CrmChatMessage::query()->sole();

    $this->actingAs($operator)->post("/crm/chat/{$call->id}/answer")->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/chat')
        && str_contains((string) $request['text'], '# Состояние проекта'));

    $answer = CrmChatMessage::query()->where('author', CrmChatMessage::AUTHOR_LAINOS)->sole();

    expect($answer->meta['context']['briefing'])->toBeNull();
});
