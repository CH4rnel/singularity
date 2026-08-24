<?php

use App\Models\CrmChatMessage;
use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\User;
use App\Services\Console\ConsoleFeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The console's heartbeat, and what the lenses do with it.
 *
 * The console is read by three people at once. Everything here exists so that
 * none of them has to press F5 to find out what the other two did — and so
 * that a console which stopped hearing from the server says so instead of
 * looking like a quiet night.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('crm.admin_wallets', ['0x00000000000000000000000000000000000000aa']);
    config()->set('crm.admin_user_ids', []);
    config()->set('services.lainos.url', null);
    config()->set('services.lain.openrouter_api_key', null);
    Storage::fake('local');
    Cache::flush();
});

function pulseOperator(string $name = 'lain'): User
{
    return User::factory()->crmAdmin()->create(['name' => $name]);
}

it('is a 404 for a stranger and a redirect for a guest', function () {
    // The heartbeat answers the same way every other address under /crm does:
    // a 403 would confirm the console exists.
    $this->get('/crm/pulse')->assertRedirect('/login');

    $this->actingAs(User::factory()->create(['wallet_address' => '0x'.str_repeat('e', 40)]))
        ->get('/crm/pulse')
        ->assertNotFound();
});

it('carries a version per lens and the rail badges', function () {
    $this->actingAs(pulseOperator())
        ->getJson('/crm/pulse')
        ->assertOk()
        ->assertJsonStructure([
            'at',
            'v' => ['now', 'tasks', 'people', 'notes', 'chat', 'files', 'machines'],
            'counts' => ['attention', 'tasks', 'chat'],
        ]);
});

it('moves the version of the lens that changed and leaves the others alone', function () {
    $operator = pulseOperator();

    $before = $this->actingAs($operator)->getJson('/crm/pulse')->json('v');

    // No contact attached: the point is that one lens moved and the
    // others did not, and a task made for somebody would move Люди too.
    CrmTask::factory()->create(['status' => 'open', 'crm_contact_id' => null]);

    $after = $this->actingAs($operator)->getJson('/crm/pulse')->json('v');

    expect($after['tasks'])->not->toBe($before['tasks'])
        // The queue is drawn from the tasks too, so it moves with them.
        ->and($after['now'])->not->toBe($before['now'])
        ->and($after['people'])->toBe($before['people'])
        ->and($after['chat'])->toBe($before['chat']);
});

it('moves a version when a row is edited or deleted, not only when one is added', function () {
    $operator = pulseOperator();
    $contact = CrmContact::factory()->create(['name' => 'первый']);

    $created = $this->actingAs($operator)->getJson('/crm/pulse')->json('v.people');

    // A second later, because these columns keep whole seconds: a version
    // built out of a count and a timestamp cannot see two writes inside one.
    // The room is the one lens where that would show, and it does not rely on
    // a version at all — see the room's own `since`.
    $this->travel(1)->second();

    $contact->update(['name' => 'первый, но исправленный']);
    $edited = $this->actingAs($operator)->getJson('/crm/pulse')->json('v.people');

    expect($edited)->not->toBe($created);

    $contact->forceDelete();
    $deleted = $this->actingAs($operator)->getJson('/crm/pulse')->json('v.people');

    // A count alone would miss the edit; a high-water mark alone would miss
    // the delete. The version carries both, which is why it is opaque.
    expect($deleted)->not->toBe($edited);
});

it('reads the queue count out of the cache and never rebuilds it', function () {
    $operator = pulseOperator();

    // Cold: unknown, and deliberately not computed — a heartbeat that
    // recomputed a thirty-day aggregate every five seconds is how a console
    // becomes the reason the database is busy.
    $cold = $this->actingAs($operator)->getJson('/crm/pulse');

    expect($cold->json('counts.attention'))->toBeNull()
        ->and(Cache::has(ConsoleFeed::CACHE_KEY))->toBeFalse();

    Cache::put(ConsoleFeed::CACHE_KEY, ['attention' => [['key' => 'incident:rpc'], ['key' => 'gas:tank']]], 30);

    expect($this->actingAs($operator)->getJson('/crm/pulse')->json('counts.attention'))->toBe(2);
});

it('counts the unread lines of the person asking, never their own', function () {
    $lain = pulseOperator('lain');
    $other = User::factory()->create([
        'name' => 'netrunner',
        'wallet_address' => '0x00000000000000000000000000000000000000bb',
    ]);
    config()->set('crm.admin_wallets', [
        '0x00000000000000000000000000000000000000aa',
        '0x00000000000000000000000000000000000000bb',
    ]);

    CrmChatMessage::create(['user_id' => $other->id, 'author' => 'operator', 'body' => 'мост встал']);
    CrmChatMessage::create(['user_id' => $lain->id, 'author' => 'operator', 'body' => 'смотрю']);

    expect($this->actingAs($lain)->getJson('/crm/pulse')->json('counts.chat'))->toBe(1);
});

it('stamps presence only for the person who has the room on screen', function () {
    $lain = pulseOperator('lain');

    $this->actingAs($lain)->getJson('/crm/pulse?lens=other')->assertOk();

    expect(DB::table('crm_chat_reads')->where('user_id', $lain->id)->value('read_at'))->toBeNull();

    $this->actingAs($lain)->getJson('/crm/pulse?lens=chat')->assertOk();

    expect(DB::table('crm_chat_reads')->where('user_id', $lain->id)->value('read_at'))->not->toBeNull();
});

it('gives presence its offset, so a browser cannot read it as local time', function () {
    // The bug this pins: the column comes back out of the driver as a bare
    // "Y-m-d H:i:s", and a browser reads a bare stamp as *its own* local
    // time — which drew somebody typing at that moment as last seen three
    // hours ago, one whole UTC offset into the past.
    $lain = pulseOperator('lain');

    $this->actingAs($lain)->get('/crm/chat')->assertOk();

    $people = $this->actingAs($lain)->getJson('/crm/chat/since?after=0')->json('people');
    $me = collect($people)->firstWhere('id', $lain->id);

    expect($me['seenAt'])->toMatch('/(Z|[+-]\d{2}:\d{2})$/')
        ->and(strtotime($me['seenAt']))->toBeGreaterThan(time() - 60);
});

it('reports lines that were said, changed and taken back', function () {
    $lain = pulseOperator('lain');
    $other = User::factory()->create([
        'name' => 'netrunner',
        'wallet_address' => '0x00000000000000000000000000000000000000bb',
    ]);

    $first = CrmChatMessage::create(['user_id' => $other->id, 'author' => 'operator', 'body' => 'первая']);
    $second = CrmChatMessage::create(['user_id' => $other->id, 'author' => 'operator', 'body' => 'вторая']);

    // What this browser holds: both lines, read a moment ago.
    $seen = $this->actingAs($lain)->getJson('/crm/chat/since?after=0')->json();

    expect($seen['messages'])->toHaveCount(2)
        ->and($seen['changed'])->toBe([])
        // The counts agree, so the id roster is not sent at all.
        ->and($seen['present'])->toBeNull();

    // One new line, one line changed under the reader, one taken back.
    $third = CrmChatMessage::create(['user_id' => $other->id, 'author' => 'operator', 'body' => 'третья']);
    $second->update(['crm_task_id' => CrmTask::factory()->create()->id]);
    $first->delete();

    $news = $this->actingAs($lain)->getJson(
        '/crm/chat/since?'.http_build_query([
            'after' => $second->id,
            'from' => $first->id,
            'held' => 2,
            'at' => $seen['at'],
        ]),
    )->json();

    expect(collect($news['messages'])->pluck('id')->all())->toBe([$third->id])
        ->and(collect($news['changed'])->pluck('id')->all())->toBe([$second->id])
        ->and($news['changed'][0]['task'])->not->toBeNull()
        ->and($news['present'])->toBe([$second->id]);
});

it('repeats the boundary second once, then goes quiet', function () {
    // The price of the inclusive edge, pinned so nobody "fixes" it back into
    // a lost line: a row written in the very second of a read comes back one
    // extra time, and then never again. Replacing a row this browser already
    // holds costs nothing; missing it costs the answer.
    $lain = pulseOperator('lain');
    $other = User::factory()->create([
        'name' => 'netrunner',
        'wallet_address' => '0x00000000000000000000000000000000000000bb',
    ]);

    $message = CrmChatMessage::create(['user_id' => $other->id, 'author' => 'operator', 'body' => 'одна']);
    $seen = $this->actingAs($lain)->getJson('/crm/chat/since?after=0')->json();

    $window = fn (string $at): array => $this->actingAs($lain)->getJson(
        '/crm/chat/since?'.http_build_query([
            'after' => $message->id,
            'from' => $message->id,
            'held' => 1,
            'at' => $at,
        ]),
    )->json();

    $this->travel(1)->second();

    $echo = $window($seen['at']);

    expect($echo['messages'])->toBe([])
        ->and(collect($echo['changed'])->pluck('id')->all())->toBe([$message->id])
        ->and($echo['present'])->toBeNull();

    // The reader's clock has moved on with the answer, and the room is quiet.
    $quiet = $window($echo['at']);

    expect($quiet['messages'])->toBe([])
        ->and($quiet['changed'])->toBe([])
        ->and($quiet['present'])->toBeNull();
});
