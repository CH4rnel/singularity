<?php

namespace App\Services\Console;

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmMessage;
use App\Models\CrmNote;
use App\Models\CrmTask;
use App\Models\SiteEvent;
use App\Services\WalletPriceService;
use App\Support\Handles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One person, as everything that is known about them in one stream.
 *
 * The dossier used to be four panels that each held a slice of the record, so
 * answering "what happened here" meant reading four sorted lists and merging
 * them by eye. Visits, trades, bridge transfers, our own messages and notes
 * are one timeline now, because that is the only order in which a story reads.
 *
 * There is no invented history: this app holds no balance series, so the row
 * that would have been "position over the year" is the person's *activity*
 * over twelve weeks, which is a thing that is actually recorded. A number with
 * no source is left out rather than estimated.
 */
class PersonDossier
{
    private const WEEKS = 12;

    /** How many events of the stream go down the wire before "show more". */
    private const EVENTS = 60;

    /**
     * The three readings of one stream.
     *
     * `touch` is what people did — our messages, their replies, notes,
     * promises; `money` is what the chain did. The split exists because the
     * two are read for different reasons: one answers "where does this
     * conversation stand", the other "what has this person actually moved".
     */
    public const VIEWS = ['all', 'touch', 'money'];

    public function __construct(
        private WalletPriceService $prices,
        private IdentityGraph $identities,
    ) {}

    /**
     * @param  string  $view  Which slice of the stream to render: everything,
     *                        only what people did, or only what money did.
     * @param  int  $limit  How many events of that slice to send.
     * @return array<string, mixed>
     */
    public function build(CrmContact $contact, string $view = 'all', int $limit = self::EVENTS): array
    {
        $contact->loadMissing(['notes.author', 'tasks.assignee:id,name', 'messages.author:id,name', 'user']);

        $view = in_array($view, self::VIEWS, true) ? $view : 'all';
        $limit = max(self::EVENTS, min($limit, 400));

        $addresses = array_values(array_filter([$contact->evm_address, $contact->solana_address]));
        $transfers = $this->transfers($addresses);
        $conversation = $this->conversation($contact);
        $price = $this->prices->quotes()['prices']['cyberia'] ?? null;

        $cyber = (float) ($contact->cyber_balance ?? 0);
        $sol = (float) ($contact->cyber_sol_balance ?? 0);

        return [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->displayName(),
                'telegram' => $contact->telegram,
                'x_handle' => $contact->x_handle,
                // The two addresses a message could be sent to, built here so
                // the page never has to guess whether a stored handle is one:
                // the sync files numeric Telegram ids in the same column, and
                // `t.me/812…` opens nothing.
                'telegram_url' => Handles::telegramUrl($contact->telegram),
                'x_url' => Handles::xUrl($contact->x_handle),
                'email' => $contact->email,
                'evm_address' => $contact->evm_address,
                'solana_address' => $contact->solana_address,
                'type' => $contact->type,
                'status' => $contact->status,
                'source' => $contact->source,
                'tags' => $contact->tags ?? [],
                'created_at' => $contact->created_at?->toIso8601String(),
                'last_synced_at' => $contact->last_synced_at?->toIso8601String(),
                'user_id' => $contact->user_id,
            ],
            'money' => [
                'cyber' => $cyber,
                'cyber_usd' => $price === null ? null : round($cyber * $price, 2),
                'cyber_sol' => $sol,
                'cyber_sol_usd' => $price === null ? null : round($sol * $price, 2),
                'price' => $price,
            ],
            'activity' => $transfers['weekly'],
            'summary' => $this->summary($contact, $transfers, $conversation),
            'tasks' => $contact->tasks->map(fn (CrmTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'overdue' => $task->isOverdue(),
                'assignee' => $task->assignee?->name,
            ])->all(),
            ...$this->stream($contact, $addresses, $view, $limit),
            /*
             * The correspondence, read as a conversation rather than as
             * events. It is in the timeline too — it is part of everything
             * that happened — but a thread is read in the order it was said
             * and a log newest-first, so the same rows carry two readings.
             */
            'conversation' => $conversation,
            /*
             * The same person, filed twice.
             *
             * One visitor arrives under an account, an EVM address and a
             * Solana address and leaves a record under each; the console used
             * to show three strangers. Everything here is an assertion
             * somebody or something made, so each row carries what justified
             * it — a link nobody can question is a link nobody trusts the
             * first time it surprises them.
             */
            'identity' => $this->identity($contact),
        ];
    }

    /**
     * Who else is this person, and on what grounds.
     *
     * `same` are the records that join this one through evidence strong enough
     * to stand alone. `suggested` are the guesses — an address a bridge paid
     * out to, which may just as easily be a friend — offered for somebody to
     * confirm rather than applied quietly. The distinction is the whole point:
     * merging two customers on a guess is worse than showing one twice.
     *
     * @return array<string, mixed>
     */
    private function identity(CrmContact $contact): array
    {
        $same = $this->identities->contactsWith($contact);

        return [
            'nodes' => IdentityGraph::nodesOf($contact),
            'same' => $same->map(fn (CrmContact $other) => [
                'id' => $other->id,
                'name' => $other->displayName(),
                'source' => $other->source,
                'evm_address' => $other->evm_address,
                'solana_address' => $other->solana_address,
                'user_id' => $other->user_id,
            ])->all(),
            'links' => $this->identities->edgesFor($contact)->map(fn ($link) => [
                'id' => $link->id,
                'left' => $link->left_kind.':'.$link->left_value,
                'right' => $link->right_kind.':'.$link->right_value,
                'source' => $link->source,
                'confidence' => $link->confidence,
                'evidence' => $link->evidence,
                'created_at' => $link->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * The one sentence at the top.
     *
     * Composed from parameters rather than prose so it reads in both
     * languages, and it only ever states things that are on the record: how
     * long they have been here, what they did last, and what we owe them.
     *
     * @param  array{rows: array<int, array<string, mixed>>, weekly: array<int, float>}  $transfers
     * @param  array<string, mixed>  $conversation
     * @return array<string, mixed>
     */
    private function summary(CrmContact $contact, array $transfers, array $conversation): array
    {
        $overdue = $contact->tasks->filter(fn (CrmTask $task) => $task->isOverdue())->count();
        $open = $contact->tasks->filter(fn (CrmTask $task) => $task->isActive())->count();
        $lastNote = $contact->notes->first();
        $lastTransfer = $transfers['rows'][0] ?? null;
        // Whole days we have been waiting on an answer, and only when it is
        // long enough to be a fact about the relationship rather than about
        // this afternoon.
        $waiting = $conversation['waiting_days'];

        return [
            'tone' => match (true) {
                $overdue > 0 => 'critical',
                $waiting !== null && $waiting >= 2 => 'warning',
                $contact->type === 'whale' => 'money',
                default => 'plain',
            },
            'key' => match (true) {
                $overdue > 0 => 'person.summary.overdue',
                $waiting !== null && $waiting >= 2 => 'person.summary.waiting',
                $lastTransfer !== null => 'person.summary.moved',
                $lastNote !== null => 'person.summary.talked',
                default => 'person.summary.quiet',
            },
            'params' => [
                'since' => $contact->created_at?->format('d.m.Y') ?? '—',
                'source' => $contact->source,
                'open' => $open,
                'overdue' => $overdue,
                'lastNote' => $lastNote?->created_at?->format('d.m') ?? '—',
                'amount' => $lastTransfer['amount'] ?? '',
                'token' => $lastTransfer['token'] ?? '',
                'when' => $lastTransfer['at'] ?? '',
                'transfers' => count($transfers['rows']),
                'waiting' => $waiting ?? 0,
                'messages' => $conversation['total'],
            ],
        ];
    }

    /**
     * The correspondence, and the two things it is actually asked.
     *
     * "When did we last write, and did they answer" is the question a dossier
     * is opened with, and until this table existed the answer lived in
     * somebody's Telegram. Both derived numbers are stated as what they are:
     *
     * `replies_in` is the **median** gap between our line and their answer,
     * not the mean — one message answered three days later while sixteen were
     * answered inside an hour describes a person who replies within the hour,
     * and the mean would say a day and a half.
     *
     * `waiting_days` is how long our last line has stood unanswered, null
     * when the last word was theirs or when there has never been one. Null is
     * never rendered as zero anywhere above this line.
     *
     * @return array<string, mixed>
     */
    private function conversation(CrmContact $contact): array
    {
        $messages = $contact->messages;
        $last = $messages->last();

        $rows = $messages->map(fn (CrmMessage $message) => [
            'id' => $message->id,
            'direction' => $message->direction,
            'channel' => $message->channel,
            'body' => $message->body,
            'at' => $message->sent_at?->toIso8601String(),
            // Who typed it in — never who said it. On an inbound line the
            // operator is the scribe, which is why the name is only ever
            // shown beside our own lines.
            'author' => $message->author?->name,
            // Who said it, when an import knew and we might not: a Discord
            // display name is not this contact's name, and a line attributed
            // to the wrong person is worse than one attributed to nobody.
            'said_by' => $message->author_name,
        ])->all();

        $waiting = null;

        if ($last !== null && $last->isOutbound() && $last->sent_at !== null) {
            $waiting = (int) floor($last->sent_at->diffInDays(now()));
        }

        return [
            'rows' => $rows,
            'total' => count($rows),
            'last' => $last === null ? null : [
                'at' => $last->sent_at?->toIso8601String(),
                'direction' => $last->direction,
                'channel' => $last->channel,
            ],
            'replies_in' => $this->replyMinutes($messages),
            'waiting_days' => $waiting,
            'options' => [
                'channels' => CrmMessage::CHANNELS,
                'directions' => CrmMessage::DIRECTIONS,
            ],
        ];
    }

    /**
     * How long they usually take to answer us, in minutes.
     *
     * Measured from the *first* unanswered line we sent, not the last: when
     * three messages go out and one reply comes back, what was waited on
     * started with the first of them. A conversation with no answered line
     * returns null rather than a zero nobody could interpret.
     *
     * @param  Collection<int, CrmMessage>  $messages
     */
    private function replyMinutes(Collection $messages): ?int
    {
        $gaps = [];
        $asked = null;

        foreach ($messages as $message) {
            if ($message->sent_at === null) {
                continue;
            }

            if ($message->isOutbound()) {
                $asked ??= $message->sent_at;

                continue;
            }

            if ($asked !== null) {
                $gaps[] = (int) round($asked->diffInMinutes($message->sent_at));
                $asked = null;
            }
        }

        if ($gaps === []) {
            return null;
        }

        sort($gaps);
        $middle = (int) floor(count($gaps) / 2);

        return count($gaps) % 2 === 0
            ? (int) round(($gaps[$middle - 1] + $gaps[$middle]) / 2)
            : $gaps[$middle];
    }

    /**
     * Everything on the record, newest first — and how much of it there is.
     *
     * Site events are joined by wallet address, which is the only link that
     * exists between a browser session and a contact — and the reason a
     * contact with no attached wallet has a shorter timeline rather than a
     * guessed one.
     *
     * The stream reads three ways, because it is read for two different
     * reasons: `touch` is what people did (our lines, their replies, notes,
     * promises), `money` is what the chain did, `all` is the story. The
     * filter is applied here rather than in the browser: a page that hides
     * rows out of the newest sixty is a page whose "only money" is really
     * "the money inside the last sixty events", which is a different claim.
     *
     * Every count is a real count of the table, never of the slice that was
     * fetched — the footer says how many events are left, and a footer that
     * counts only what it already holds always says zero.
     *
     * @param  array<int, string>  $addresses
     * @return array<string, mixed>
     */
    private function stream(CrmContact $contact, array $addresses, string $view, int $limit): array
    {
        $rows = [];
        $counts = ['touch' => 0, 'money' => 0, 'other' => 0];

        foreach ($contact->notes as $note) {
            /** @var CrmNote $note */
            $rows[] = [
                'group' => 'touch',
                'kind' => 'note',
                'id' => $note->id,
                'at' => $note->created_at?->toIso8601String(),
                'title' => 'person.event.note',
                'params' => ['author' => $note->author?->name ?? '—'],
                'body' => $note->body,
                'amount' => null,
            ];
        }

        $counts['touch'] += $contact->notes->count();

        foreach ($contact->messages as $message) {
            /** @var CrmMessage $message */
            $rows[] = [
                'group' => 'touch',
                'kind' => $message->isOutbound() ? 'said' : 'heard',
                'id' => $message->id,
                'at' => $message->sent_at?->toIso8601String(),
                'title' => $message->isOutbound() ? 'person.event.said' : 'person.event.heard',
                'params' => [
                    'channel' => $message->channel,
                    'author' => $message->author?->name ?? '—',
                ],
                'body' => $message->body,
                'amount' => null,
            ];
        }

        $counts['touch'] += $contact->messages->count();

        foreach ($contact->tasks as $task) {
            /** @var CrmTask $task */
            $rows[] = [
                'group' => 'touch',
                'kind' => 'task',
                'id' => $task->id,
                'at' => ($task->completed_at ?? $task->created_at)?->toIso8601String(),
                'title' => $task->status === 'done' ? 'person.event.taskDone' : 'person.event.task',
                'params' => ['assignee' => $task->assignee?->name ?? '—', 'priority' => $task->priority],
                'body' => $task->title,
                'amount' => null,
            ];
        }

        $counts['touch'] += $contact->tasks->count();

        if ($addresses !== []) {
            $lowered = array_map('mb_strtolower', $addresses);

            $bridges = BridgeRequest::query()
                ->where(fn ($query) => $query
                    ->whereIn('sender_address', $lowered)
                    ->orWhereIn('recipient_address', $lowered));

            $counts['money'] = (clone $bridges)->count();

            // Fetched at the full limit rather than a fixed page: the merge
            // below only guarantees a correct top `$limit` if every source
            // offered that many candidates.
            foreach ($bridges->orderByDesc('created_at')->limit($limit)->get() as $request) {
                $outbound = in_array(mb_strtolower((string) $request->sender_address), $lowered, true);

                $rows[] = [
                    'group' => 'money',
                    'kind' => 'bridge',
                    'id' => $request->id,
                    'at' => $request->created_at?->toIso8601String(),
                    'title' => $outbound ? 'person.event.bridgeOut' : 'person.event.bridgeIn',
                    'params' => [
                        'direction' => (string) $request->direction,
                        'status' => (string) $request->status,
                        'token' => (string) $request->token,
                    ],
                    'body' => null,
                    'amount' => [
                        'value' => $this->trim((float) $request->amount),
                        'token' => (string) $request->token,
                        'outbound' => $outbound,
                    ],
                ];
            }

            $visits = SiteEvent::query()->whereIn('wallet_address', $lowered);
            $counts['other'] = (clone $visits)->count();

            $events = $visits
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id', 'event', 'page', 'created_at']);

            foreach ($events as $event) {
                $rows[] = [
                    'group' => 'other',
                    'kind' => 'visit',
                    'id' => $event->id,
                    'at' => $event->created_at?->toIso8601String(),
                    'title' => 'person.event.'.$event->event,
                    'params' => ['page' => (string) ($event->page ?? '')],
                    'body' => null,
                    'amount' => null,
                ];
            }
        }

        if ($contact->created_at !== null) {
            $rows[] = [
                'group' => 'other',
                'kind' => 'system',
                'id' => 0,
                'at' => $contact->created_at->toIso8601String(),
                'title' => 'person.event.appeared',
                'params' => ['source' => $contact->source],
                'body' => null,
                'amount' => null,
            ];

            $counts['other']++;
        }

        $rows = array_values(array_filter(
            $rows,
            fn (array $row) => $view === 'all' || $row['group'] === $view,
        ));

        usort($rows, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        $total = match ($view) {
            'touch' => $counts['touch'],
            'money' => $counts['money'],
            default => $counts['touch'] + $counts['money'] + $counts['other'],
        };

        return [
            'timeline' => array_slice($rows, 0, $limit),
            'events' => [
                'view' => $view,
                'limit' => $limit,
                'shown' => min(count($rows), $limit),
                'total' => $total,
                // What is left under the fold, and how far back it goes. The
                // floor is when this record was opened, which is the one date
                // that is true whatever the sources hold.
                'more' => max(0, $total - min(count($rows), $limit)),
                'since' => $contact->created_at?->toIso8601String(),
                'counts' => [
                    'all' => $counts['touch'] + $counts['money'] + $counts['other'],
                    'touch' => $counts['touch'],
                    'money' => $counts['money'],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $addresses
     * @return array{rows: array<int, array<string, mixed>>, weekly: array<int, float>}
     */
    private function transfers(array $addresses): array
    {
        if ($addresses === []) {
            return ['rows' => [], 'weekly' => array_fill(0, self::WEEKS, 0.0)];
        }

        $lowered = array_map('mb_strtolower', $addresses);

        $requests = BridgeRequest::query()
            ->where(fn ($query) => $query
                ->whereIn('sender_address', $lowered)
                ->orWhereIn('recipient_address', $lowered))
            ->where('created_at', '>=', now()->subWeeks(self::WEEKS))
            ->orderByDesc('created_at')
            ->get(['sender_address', 'token', 'amount', 'created_at']);

        $weekly = array_fill(0, self::WEEKS, 0.0);
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks(self::WEEKS - 1);
        $rows = [];

        foreach ($requests as $request) {
            $at = CarbonImmutable::parse($request->created_at);
            $week = (int) floor($start->diffInWeeks($at));

            if ($week >= 0 && $week < self::WEEKS) {
                $weekly[$week]++;
            }

            $rows[] = [
                'at' => $at->format('d.m'),
                'amount' => $this->trim((float) $request->amount),
                'token' => (string) $request->token,
            ];
        }

        return ['rows' => $rows, 'weekly' => $weekly];
    }

    private function trim(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 4, '.', ' '), '0'), '.');
    }
}
