<?php

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmMessage;
use App\Models\CrmNote;
use App\Models\CrmTask;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The correspondence on a dossier.
 *
 * What is pinned here is the part that is not obvious from the shape of the
 * table: which way a line points, what the derived numbers mean when there is
 * nothing to derive them from, and that the stream's counts are counts of the
 * record rather than of the page that happens to be on screen.
 */
function messageOperator(): User
{
    return User::factory()->crmAdmin()->create(['name' => 'lain']);
}

test('an operator writes down a line and it lands on the dossier', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    $this->actingAs($operator)
        ->post(route('crm.messages.store', $contact), [
            'body' => 'Спросил про корпоративный тариф',
            'direction' => 'out',
            'channel' => 'telegram',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $message = CrmMessage::query()->sole();

    expect($message->crm_contact_id)->toBe($contact->id)
        ->and($message->user_id)->toBe($operator->id)
        ->and($message->direction)->toBe('out')
        // Empty means now: a line is normally written down as it is said.
        ->and($message->sent_at)->not->toBeNull();

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.total', 1)
            ->where('conversation.rows.0.direction', 'out')
            ->where('conversation.rows.0.body', 'Спросил про корпоративный тариф')
            ->where('conversation.rows.0.author', 'lain')
            ->where('conversation.last.direction', 'out')
            ->etc());
});

test('a line said earlier is filed at the moment it was said', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    $this->actingAs($operator)
        ->post(route('crm.messages.store', $contact), [
            'body' => 'Ответил вчера вечером',
            'direction' => 'in',
            'channel' => 'discord',
            'sent_at' => now()->subDay()->toIso8601String(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $message = CrmMessage::query()->sole();

    expect($message->sent_at->isYesterday())->toBeTrue()
        ->and($message->channel)->toBe('discord');
});

test('a direction or a channel the console does not know is refused', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    $this->actingAs($operator)
        ->post(route('crm.messages.store', $contact), [
            'body' => 'x',
            'direction' => 'sideways',
            'channel' => 'telegram',
        ])
        ->assertSessionHasErrors('direction');

    $this->actingAs($operator)
        ->post(route('crm.messages.store', $contact), [
            'body' => 'x',
            'direction' => 'out',
            'channel' => 'carrier pigeon',
        ])
        ->assertSessionHasErrors('channel');

    expect(CrmMessage::query()->count())->toBe(0);
});

test('the reply time is the median of what they actually took', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    /*
     * Three exchanges: answered in ten minutes, in twenty, and once after
     * three days. The mean would call this a person who answers in a day; the
     * median calls it twenty minutes, which is what anybody reading the
     * dossier would say out loud.
     */
    $exchanges = [
        [now()->subDays(20), 10],
        [now()->subDays(10), 20],
        [now()->subDays(5), 60 * 24 * 3],
    ];

    foreach ($exchanges as [$asked, $minutes]) {
        CrmMessage::factory()->create([
            'crm_contact_id' => $contact->id,
            'direction' => 'out',
            'sent_at' => $asked,
        ]);

        CrmMessage::factory()->inbound()->create([
            'crm_contact_id' => $contact->id,
            'sent_at' => $asked->copy()->addMinutes($minutes),
        ]);
    }

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.replies_in', 20)
            ->where('conversation.waiting_days', null)
            ->where('conversation.last.direction', 'in')
            ->etc());
});

test('the gap is measured from the first unanswered line, not the last', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    $first = now()->subDays(3);

    CrmMessage::factory()->create([
        'crm_contact_id' => $contact->id,
        'direction' => 'out',
        'sent_at' => $first,
    ]);

    // A nudge two hours later. What was waited on still started with the
    // first line, so the answer took four hours and not two.
    CrmMessage::factory()->create([
        'crm_contact_id' => $contact->id,
        'direction' => 'out',
        'sent_at' => $first->copy()->addHours(2),
    ]);

    CrmMessage::factory()->inbound()->create([
        'crm_contact_id' => $contact->id,
        'sent_at' => $first->copy()->addHours(4),
    ]);

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.replies_in', 240)
            ->etc());
});

test('a conversation nobody answered says so instead of saying zero', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    CrmMessage::factory()->create([
        'crm_contact_id' => $contact->id,
        'direction' => 'out',
        'sent_at' => now()->subDays(4),
    ]);

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.replies_in', null)
            ->where('conversation.waiting_days', 4)
            // Four days of silence outranks a whale's balance in the one
            // sentence at the top.
            ->where('summary.key', 'person.summary.waiting')
            ->where('summary.params.waiting', 4)
            ->etc());
});

test('a dossier with no correspondence carries the empty conversation', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.total', 0)
            ->where('conversation.last', null)
            ->where('conversation.replies_in', null)
            ->where('conversation.waiting_days', null)
            ->etc());
});

test('a line is deleted and the stream forgets it', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();
    $message = CrmMessage::factory()->create(['crm_contact_id' => $contact->id]);

    $this->actingAs($operator)
        ->delete(route('crm.messages.destroy', $message))
        ->assertRedirect();

    $this->assertDatabaseMissing('crm_messages', ['id' => $message->id]);
});

test('nobody outside the console may read or write a correspondence', function () {
    $stranger = User::factory()->create(['wallet_address' => '0x'.str_repeat('e', 40)]);
    $contact = CrmContact::factory()->create();
    $message = CrmMessage::factory()->create(['crm_contact_id' => $contact->id]);

    $this->actingAs($stranger)
        ->post(route('crm.messages.store', $contact), [
            'body' => 'x',
            'direction' => 'out',
            'channel' => 'telegram',
        ])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->delete(route('crm.messages.destroy', $message))
        ->assertNotFound();

    $this->assertDatabaseHas('crm_messages', ['id' => $message->id]);
});

test('the correspondence is part of the record and reads inside it', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create(['evm_address' => '0x'.str_repeat('a', 40)]);

    CrmMessage::factory()->create([
        'crm_contact_id' => $contact->id,
        'direction' => 'out',
        'sent_at' => now()->subHour(),
    ]);

    CrmMessage::factory()->inbound()->create([
        'crm_contact_id' => $contact->id,
        'sent_at' => now()->subMinutes(30),
    ]);

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'timeline',
                fn ($rows) => collect($rows)->pluck('kind')->contains('said')
                    && collect($rows)->pluck('kind')->contains('heard'),
            )
            ->etc());
});

test('the stream reads three ways and counts the record, not the page', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create(['evm_address' => '0x'.str_repeat('b', 40)]);

    CrmNote::factory()->count(2)->create(['crm_contact_id' => $contact->id]);
    CrmMessage::factory()->count(3)->create(['crm_contact_id' => $contact->id]);
    CrmTask::factory()->create(['crm_contact_id' => $contact->id]);
    foreach ([1, 2] as $nonce) {
        BridgeRequest::create([
            'direction' => 'evm_to_sol',
            'token' => 'CYBER.sol',
            'source_chain' => 'cyberia',
            'source_tx_hash' => '0x'.str_repeat((string) $nonce, 64),
            'source_nonce' => $nonce,
            'sender_address' => $contact->evm_address,
            'recipient_address' => 'So1anaRecipientAddrrrrrrrrrrrrrrrrrrrrrrr11',
            'amount' => '1.5',
            'status' => 'completed',
        ]);
    }

    // Everything: six touches, two transfers, and the row that says the
    // record was opened.
    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.view', 'all')
            ->where('events.counts.touch', 6)
            ->where('events.counts.money', 2)
            ->where('events.counts.all', 9)
            ->where('events.total', 9)
            ->where('events.more', 0)
            ->etc());

    $this->actingAs($operator)
        ->get(route('crm.show', $contact).'?events=money')
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.view', 'money')
            ->where('events.total', 2)
            ->where('timeline', fn ($rows) => count($rows) === 2
                && collect($rows)->every(fn ($row) => $row['group'] === 'money'))
            ->etc());

    $this->actingAs($operator)
        ->get(route('crm.show', $contact).'?events=touch')
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.view', 'touch')
            ->where('events.total', 6)
            ->where('timeline', fn ($rows) => count($rows) === 6
                && collect($rows)->every(fn ($row) => $row['group'] === 'touch'))
            ->etc());
});

test('a slice nobody asked for falls back to the whole stream', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    $this->actingAs($operator)
        ->get(route('crm.show', $contact).'?events=everything')
        ->assertInertia(fn (Assert $page) => $page->where('events.view', 'all')->etc());
});

test('the footer says how much is left underneath and how far back it goes', function () {
    $operator = messageOperator();
    $contact = CrmContact::factory()->create();

    CrmMessage::factory()->count(70)->create(['crm_contact_id' => $contact->id]);

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.shown', 60)
            // 70 lines plus the row that opened the record.
            ->where('events.total', 71)
            ->where('events.more', 11)
            ->where('timeline', fn ($rows) => count($rows) === 60)
            ->etc());

    $this->actingAs($operator)
        ->get(route('crm.show', $contact).'?rows=120')
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.more', 0)
            ->where('timeline', fn ($rows) => count($rows) === 71)
            ->etc());
});
