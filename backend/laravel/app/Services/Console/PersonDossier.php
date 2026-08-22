<?php

namespace App\Services\Console;

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmNote;
use App\Models\CrmTask;
use App\Models\SiteEvent;
use App\Services\WalletPriceService;
use Carbon\CarbonImmutable;

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

    public function __construct(
        private WalletPriceService $prices,
        private IdentityGraph $identities,
    ) {}

    /** @return array<string, mixed> */
    public function build(CrmContact $contact): array
    {
        $contact->loadMissing(['notes.author', 'tasks.assignee:id,name', 'user']);

        $addresses = array_values(array_filter([$contact->evm_address, $contact->solana_address]));
        $transfers = $this->transfers($addresses);
        $price = $this->prices->quotes()['prices']['cyberia'] ?? null;

        $cyber = (float) ($contact->cyber_balance ?? 0);
        $sol = (float) ($contact->cyber_sol_balance ?? 0);

        return [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name ?: ($contact->telegram ?: ($contact->evm_address ?: '#'.$contact->id)),
                'telegram' => $contact->telegram,
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
            'summary' => $this->summary($contact, $transfers),
            'tasks' => $contact->tasks->map(fn (CrmTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'overdue' => $task->isOverdue(),
                'assignee' => $task->assignee?->name,
            ])->all(),
            'timeline' => $this->timeline($contact, $addresses),
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
                'name' => $other->name ?: ($other->telegram ?: ($other->evm_address ?: '#'.$other->id)),
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
     * @return array<string, mixed>
     */
    private function summary(CrmContact $contact, array $transfers): array
    {
        $overdue = $contact->tasks->filter(fn (CrmTask $task) => $task->isOverdue())->count();
        $open = $contact->tasks->filter(fn (CrmTask $task) => $task->isActive())->count();
        $lastNote = $contact->notes->first();
        $lastTransfer = $transfers['rows'][0] ?? null;

        return [
            'tone' => $overdue > 0 ? 'critical' : ($contact->type === 'whale' ? 'money' : 'plain'),
            'key' => match (true) {
                $overdue > 0 => 'person.summary.overdue',
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
            ],
        ];
    }

    /**
     * Everything on the record, newest first.
     *
     * Site events are joined by wallet address, which is the only link that
     * exists between a browser session and a contact — and the reason a
     * contact with no attached wallet has a shorter timeline rather than a
     * guessed one.
     *
     * @param  array<int, string>  $addresses
     * @return array<int, array<string, mixed>>
     */
    private function timeline(CrmContact $contact, array $addresses): array
    {
        $rows = [];

        foreach ($contact->notes as $note) {
            /** @var CrmNote $note */
            $rows[] = [
                'kind' => 'note',
                'at' => $note->created_at?->toIso8601String(),
                'title' => 'person.event.note',
                'params' => ['author' => $note->author?->name ?? '—'],
                'body' => $note->body,
                'amount' => null,
            ];
        }

        foreach ($contact->tasks as $task) {
            /** @var CrmTask $task */
            $rows[] = [
                'kind' => 'task',
                'at' => ($task->completed_at ?? $task->created_at)?->toIso8601String(),
                'title' => $task->status === 'done' ? 'person.event.taskDone' : 'person.event.task',
                'params' => ['assignee' => $task->assignee?->name ?? '—', 'priority' => $task->priority],
                'body' => $task->title,
                'amount' => null,
            ];
        }

        if ($addresses !== []) {
            $lowered = array_map('mb_strtolower', $addresses);

            $requests = BridgeRequest::query()
                ->where(fn ($query) => $query
                    ->whereIn('sender_address', $lowered)
                    ->orWhereIn('recipient_address', $lowered))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            foreach ($requests as $request) {
                $outbound = in_array(mb_strtolower((string) $request->sender_address), $lowered, true);

                $rows[] = [
                    'kind' => 'bridge',
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

            $events = SiteEvent::query()
                ->whereIn('wallet_address', $lowered)
                ->orderByDesc('created_at')
                ->limit(30)
                ->get(['event', 'page', 'created_at']);

            foreach ($events as $event) {
                $rows[] = [
                    'kind' => 'visit',
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
                'kind' => 'system',
                'at' => $contact->created_at->toIso8601String(),
                'title' => 'person.event.appeared',
                'params' => ['source' => $contact->source],
                'body' => null,
                'amount' => null,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($rows, 0, 60);
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
