<?php

namespace App\Services\Console;

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmNote;
use App\Models\CrmTask;
use App\Services\WalletPriceService;
use App\Support\Handles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Люди" — the same contacts, read as what happened to them.
 *
 * Two things changed against the old table. The filters became segments: a
 * filter is a question re-asked by hand every time ("type: whale" plus
 * "status: customer"), a segment is that question saved with its rule visible,
 * and the same saved question is what a report is made of. And the middle of
 * the row stopped being database columns — it says what happened to the
 * person (withdrew 40% in three days, silent for 34 days, replied and is
 * waiting on us), because that is what decides whether anyone writes to them
 * today.
 *
 * Everything here is derived from records this app already holds: notes,
 * tasks and the bridge ledger. Nothing is invented for the sake of a fuller
 * row — a contact with no history says so.
 */
class PeopleLens
{
    /** How many rows one screen of the lens carries. */
    private const ROWS = 40;

    /** Weeks in the little activity bar on each row. */
    private const WEEKS = 12;

    public function __construct(private WalletPriceService $prices) {}

    /**
     * The segments, each with its rule and its count.
     *
     * The rule travels with the segment on purpose: an operator who cannot
     * see "customer with nothing on record for 30 days" cannot tell whether
     * an empty segment means good news or a broken definition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function segments(): array
    {
        $segments = [];

        foreach (array_keys(self::definitions()) as $key) {
            $segments[] = [
                'key' => $key,
                'count' => $this->query($key)->count(),
                'tone' => self::definitions()[$key]['tone'],
            ];
        }

        return $segments;
    }

    /**
     * The segment definitions: one query apiece, named.
     *
     * @return array<string, array{tone: string, apply: callable(Builder<CrmContact>): void}>
     */
    private static function definitions(): array
    {
        $silence = (int) config('crm.console.silence_days', 30);

        return [
            'all' => [
                'tone' => 'plain',
                'apply' => function (Builder $query): void {},
            ],
            'whales' => [
                'tone' => 'money',
                'apply' => fn (Builder $query) => $query->where('type', 'whale'),
            ],
            'new_whales' => [
                'tone' => 'money',
                'apply' => fn (Builder $query) => $query
                    ->where('type', 'whale')
                    ->where('created_at', '>=', now()->subDays(30)),
            ],
            'awaiting' => [
                'tone' => 'accent',
                'apply' => fn (Builder $query) => $query->whereHas(
                    'tasks',
                    fn ($tasks) => $tasks->active(),
                ),
            ],
            // The one that reads as a report line: a customer with nothing on
            // record for a month. Bridge activity counts as a record even
            // though it is not ours, because a person who is still moving
            // money has not gone quiet — they have gone quiet *with us*.
            'silent_customers' => [
                'tone' => 'warning',
                'apply' => fn (Builder $query) => $query
                    ->where('status', 'customer')
                    ->whereDoesntHave('notes', fn ($notes) => $notes->where('created_at', '>=', now()->subDays($silence)))
                    ->where('updated_at', '<', now()->subDays($silence)),
            ],
            'one_and_done' => [
                'tone' => 'warning',
                'apply' => fn (Builder $query) => $query
                    ->whereIn('status', ['qualified', 'customer'])
                    ->whereDoesntHave('notes')
                    ->where('created_at', '<', now()->subDays($silence)),
            ],
            'cold_leads' => [
                'tone' => 'plain',
                'apply' => fn (Builder $query) => $query
                    ->where('type', 'lead')
                    ->where('status', 'new')
                    ->whereDoesntHave('notes'),
            ],
            'solana_only' => [
                'tone' => 'plain',
                'apply' => fn (Builder $query) => $query
                    ->whereNotNull('solana_address')
                    ->whereNull('evm_address'),
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /** @return Builder<CrmContact> */
    private function query(string $segment): Builder
    {
        $query = CrmContact::query();
        $apply = self::definitions()[$segment]['apply'] ?? null;

        if ($apply !== null) {
            $apply($query);
        }

        return $query;
    }

    /**
     * The rows of one segment, newest signal first.
     *
     * @return array<string, mixed>
     */
    public function rows(string $segment, ?string $search = null, int $limit = self::ROWS): array
    {
        $limit = max(self::ROWS, min($limit, 400));
        $total = $this->query($segment)->when($search, fn ($q) => $q->search($search))->count();

        // Twice the asked-for rows are read because the sort is by signal
        // freshness, which is computed here rather than in SQL: taking exactly
        // forty by `updated_at` and then reordering them would put the fortieth
        // row's signal above the forty-first's without ever having seen it.
        $contacts = $this->query($segment)
            ->when($search, fn ($q) => $q->search($search))
            ->orderByDesc('updated_at')
            ->limit($limit * 2)
            ->get();

        if ($contacts->isEmpty()) {
            return ['rows' => [], 'total' => $total, 'shown' => 0, 'limit' => $limit, 'more' => false];
        }

        $notes = $this->latestNotes($contacts);
        $tasks = $this->openTasks($contacts);
        $transfers = $this->transfers($contacts);
        $price = $this->prices->quotes()['prices']['cyberia'] ?? null;

        $rows = $contacts->map(function (CrmContact $contact) use ($notes, $tasks, $transfers, $price): array {
            $signal = $this->signal($contact, $notes, $tasks, $transfers);
            $cyber = (float) ($contact->cyber_balance ?? 0);
            $sol = (float) ($contact->cyber_sol_balance ?? 0);

            // Where a message to this person would go. Telegram first because
            // that is where a conversation already exists; X second because a
            // person found on X is reachable nowhere else. Both may be
            // missing, and a numeric Telegram id (all the sync knows about
            // somebody without a username) is not an address — an action that
            // opens a dead page is worse than no action.
            $write = Handles::telegramUrl($contact->telegram) ?? Handles::xUrl($contact->x_handle);

            return [
                'id' => $contact->id,
                'name' => $contact->displayName(),
                'handle' => $contact->displayHandle(),
                'type' => $contact->type,
                'status' => $contact->status,
                'usd' => $price === null ? null : round(($cyber + $sol) * $price),
                'signal' => $signal,
                'spark' => $transfers['weekly'][$contact->id] ?? array_fill(0, self::WEEKS, 0),
                'write' => $write,
                'action' => $write !== null ? 'write' : 'dossier',
            ];
        })
            ->sortByDesc(fn (array $row) => $row['signal']['at'] ?? '')
            ->values()
            ->take($limit)
            ->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'shown' => count($rows),
            'limit' => $limit,
            'more' => $total > count($rows),
        ];
    }

    /**
     * What happened to this person, most recent first.
     *
     * Silence is a signal too, and the only one that has to be derived rather
     * than read: nothing happening is invisible in every table ever written,
     * and it is exactly what a customer looks like just before they leave.
     *
     * @param  Collection<int, CrmNote>  $notes
     * @param  Collection<int, CrmTask>  $tasks
     * @param  array{last: array<int, array<string, mixed>>, weekly: array<int, array<int, float>>}  $transfers
     * @return array<string, mixed>
     */
    private function signal(CrmContact $contact, Collection $notes, Collection $tasks, array $transfers): array
    {
        $candidates = [];

        $note = $notes->get($contact->id);

        if ($note !== null) {
            $candidates[] = [
                'key' => 'signal.note',
                'at' => CarbonImmutable::parse($note->created_at)->toIso8601String(),
                'params' => ['body' => mb_substr((string) $note->body, 0, 90)],
                'tone' => 'accent',
            ];
        }

        $task = $tasks->get($contact->id);

        if ($task !== null) {
            $candidates[] = [
                'key' => $task->isOverdue() ? 'signal.taskOverdue' : 'signal.taskOpen',
                'at' => CarbonImmutable::parse($task->updated_at)->toIso8601String(),
                'params' => ['task' => $task->title, 'due' => $task->due_at?->format('d.m') ?? ''],
                'tone' => $task->isOverdue() ? 'critical' : 'accent',
            ];
        }

        $transfer = $transfers['last'][$contact->id] ?? null;

        if ($transfer !== null) {
            $candidates[] = [
                'key' => $transfer['outbound'] ? 'signal.moneyOut' : 'signal.moneyIn',
                'at' => $transfer['at'],
                'params' => [
                    'amount' => $transfer['amount'],
                    'token' => $transfer['token'],
                    'direction' => $transfer['direction'],
                ],
                'tone' => $transfer['outbound'] ? 'warning' : 'money',
            ];
        }

        if ($contact->created_at !== null) {
            $candidates[] = [
                'key' => $contact->type === 'whale' ? 'signal.becameWhale' : 'signal.appeared',
                'at' => CarbonImmutable::parse($contact->created_at)->toIso8601String(),
                'params' => ['source' => $contact->source],
                'tone' => $contact->type === 'whale' ? 'money' : 'plain',
            ];
        }

        usort($candidates, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        $freshest = $candidates[0] ?? [
            'key' => 'signal.nothing',
            'at' => null,
            'params' => [],
            'tone' => 'plain',
        ];

        $silence = (int) config('crm.console.silence_days', 30);
        $days = $freshest['at'] === null
            ? null
            : (int) CarbonImmutable::parse($freshest['at'])->diffInDays(CarbonImmutable::now());

        // Past the silence threshold the freshest thing on record stops being
        // the headline: "withdrew 40% three months ago" is a worse summary of
        // this person than "silent for 94 days".
        if ($days !== null && $days >= $silence) {
            return [
                'key' => 'signal.silent',
                'at' => $freshest['at'],
                'params' => ['days' => $days, 'was' => $freshest['key']],
                'tone' => 'warning',
            ];
        }

        return $freshest;
    }

    /**
     * @param  Collection<int, CrmContact>  $contacts
     * @return Collection<int, CrmNote>
     */
    private function latestNotes(Collection $contacts): Collection
    {
        return CrmNote::query()
            ->whereIn('crm_contact_id', $contacts->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->keyBy('crm_contact_id');
    }

    /**
     * @param  Collection<int, CrmContact>  $contacts
     * @return Collection<int, CrmTask>
     */
    private function openTasks(Collection $contacts): Collection
    {
        return CrmTask::query()
            ->active()
            ->whereIn('crm_contact_id', $contacts->pluck('id'))
            ->byDueDate()
            ->get()
            ->keyBy('crm_contact_id');
    }

    /**
     * The bridge ledger, folded onto the contacts by address.
     *
     * One query for the newest transfers and one for the weekly counts, so a
     * screen of forty people costs two reads rather than eighty.
     *
     * @param  Collection<int, CrmContact>  $contacts
     * @return array{last: array<int, array<string, mixed>>, weekly: array<int, array<int, float>>}
     */
    private function transfers(Collection $contacts): array
    {
        $owner = [];

        foreach ($contacts as $contact) {
            foreach (array_filter([$contact->evm_address, $contact->solana_address]) as $address) {
                $owner[mb_strtolower($address)] = $contact->id;
            }
        }

        if ($owner === []) {
            return ['last' => [], 'weekly' => []];
        }

        $addresses = array_keys($owner);

        $requests = BridgeRequest::query()
            ->where(fn ($query) => $query
                ->whereIn('sender_address', $addresses)
                ->orWhereIn('recipient_address', $addresses))
            ->where('created_at', '>=', now()->subWeeks(self::WEEKS))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['sender_address', 'recipient_address', 'token', 'amount', 'direction', 'created_at']);

        $last = [];
        $weekly = [];
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks(self::WEEKS - 1);

        foreach ($requests as $request) {
            $sender = $owner[mb_strtolower((string) $request->sender_address)] ?? null;
            $recipient = $owner[mb_strtolower((string) $request->recipient_address)] ?? null;
            $contactId = $sender ?? $recipient;

            if ($contactId === null) {
                continue;
            }

            $at = CarbonImmutable::parse($request->created_at);

            $last[$contactId] ??= [
                'at' => $at->toIso8601String(),
                'amount' => rtrim(rtrim(number_format((float) $request->amount, 4, '.', ' '), '0'), '.'),
                'token' => (string) $request->token,
                'direction' => (string) $request->direction,
                'outbound' => $sender !== null,
            ];

            $week = (int) floor($start->diffInWeeks($at));

            if ($week >= 0 && $week < self::WEEKS) {
                $weekly[$contactId] ??= array_fill(0, self::WEEKS, 0.0);
                $weekly[$contactId][$week]++;
            }
        }

        return ['last' => $last, 'weekly' => $weekly];
    }
}
