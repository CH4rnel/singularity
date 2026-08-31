<?php

namespace App\Services\Console;

use App\Models\CrmChatFile;
use App\Models\CrmChatMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The room, and the same room read as a pile of files.
 *
 * One stream and two lenses on it, the way "Сегменты" in Люди is one table
 * and several saved questions. Nothing here is a folder: a file's address is
 * the message that brought it, which is also the only place its reason is
 * written down.
 */
class ChatRoom
{
    public function __construct(private LainOsRoom $lainos) {}

    /**
     * One page of the room for $viewer.
     *
     * `$before` walks backwards through history; the room opens on the newest
     * page, because the question an operator arrives with is what was just
     * said and never what was said in June.
     *
     * @return array<string, mixed>
     */
    public function page(User $viewer, ?int $before = null): array
    {
        $size = max(10, (int) config('crm.chat.page_size', 60));

        $query = CrmChatMessage::query()
            ->with(['sender:id,name', 'files', 'contact:id,name', 'task:id,title'])
            ->latest('id')
            ->limit($size + 1);

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $rows = $query->get();
        $more = $rows->count() > $size;
        $messages = $rows->take($size)->reverse()->values();

        return [
            'messages' => $messages->map(fn (CrmChatMessage $m) => $this->message($m, $viewer))->all(),
            'older' => $more ? ($messages->first()?->id) : null,
            // Which window is on screen: null is the newest one, and any
            // other value is history, which the room says out loud so
            // nobody types into what looks like the present.
            'before' => $before,
            'unreadFrom' => $this->lastRead($viewer),
            // The server's clock at the moment this window was read. The page
            // hands it back on its next poll, which is how "what changed
            // since I last looked" is answered without trusting the reader's
            // machine to agree with this one about what time it is.
            'at' => CarbonImmutable::now()->toIso8601String(),
            'people' => $this->people($viewer),
            'recentFiles' => $this->recentFiles(),
            'fileCount' => CrmChatFile::query()->count(),
            'lainos' => $this->lainos->status(),
            'limits' => $this->limits(),
        ];
    }

    /**
     * What happened to the room since the reader last looked.
     *
     * Three things can happen to a window of a conversation, and a poll that
     * only reports the first of them leaves the other two on screen until
     * somebody reloads: a line is **said** (new), a line **changes** (an
     * answer lands on the call that asked for it, a line becomes a task), and
     * a line is **taken back** (deleted, with its files). All three are
     * answered against the window the reader actually holds — $from..$after,
     * $held lines inside it — so the cost is bounded by what is on screen and
     * not by how long the room has existed.
     *
     * `present` is the exact remedy for a deletion and is sent only when the
     * count disagrees: the reader drops whatever id is missing from it. On an
     * agreeing count it is null, which is the ordinary case and costs one
     * `count(*)` over a range of the primary key.
     *
     * @param  int  $after  The newest id the reader holds.
     * @param  int|null  $from  The oldest id in their window.
     * @param  int|null  $held  How many lines they hold inside that window.
     * @param  string|null  $changedAfter  Our own clock, handed back.
     * @return array<string, mixed>
     */
    public function since(
        User $viewer,
        int $after,
        ?int $from = null,
        ?int $held = null,
        ?string $changedAfter = null,
    ): array {
        // Read before the queries, never after: a line written in between
        // would otherwise fall into the gap between this stamp and the rows
        // it is supposed to cover, and be missed forever.
        $at = CarbonImmutable::now();

        $messages = CrmChatMessage::query()
            ->with(['sender:id,name', 'files', 'contact:id,name', 'task:id,title'])
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $changed = [];
        $present = null;

        if ($from !== null && $from <= $after) {
            if ($changedAfter !== null) {
                $changed = CrmChatMessage::query()
                    ->with(['sender:id,name', 'files', 'contact:id,name', 'task:id,title'])
                    ->whereBetween('id', [$from, $after])
                    // `>=` and not `>`: this column keeps whole seconds, so a
                    // line changed in the very second of the reader's last
                    // poll is not "after" it and would be lost for good. The
                    // cost of the inclusive edge is that one boundary second's
                    // rows come back one extra time; the cost of the exclusive
                    // one is an answer nobody ever sees.
                    ->where('updated_at', '>=', CarbonImmutable::parse($changedAfter))
                    ->orderBy('id')
                    ->limit(200)
                    ->get()
                    ->map(fn (CrmChatMessage $m) => $this->message($m, $viewer))
                    ->all();
            }

            if ($held !== null && CrmChatMessage::query()->whereBetween('id', [$from, $after])->count() !== $held) {
                $present = CrmChatMessage::query()
                    ->whereBetween('id', [$from, $after])
                    ->orderBy('id')
                    ->pluck('id')
                    ->all();
            }
        }

        return [
            'messages' => $messages->map(fn (CrmChatMessage $m) => $this->message($m, $viewer))->all(),
            'changed' => $changed,
            'present' => $present,
            'at' => $at->toIso8601String(),
            'people' => $this->people($viewer),
            'fileCount' => CrmChatFile::query()->count(),
        ];
    }

    /**
     * The files lens: segments down the side, one segment's files in the
     * table, and what the room is holding on disk.
     *
     * @return array<string, mixed>
     */
    public function files(string $segment): array
    {
        $segments = $this->segments();
        $segment = array_key_exists($segment, $segments) ? $segment : 'all';

        $files = $this->segmentQuery($segment)
            ->with(['uploader:id,name', 'message:id,body,user_id'])
            ->latest('id')
            ->limit(200)
            ->get();

        return [
            'segment' => $segment,
            'segments' => collect($segments)
                ->map(fn (array $definition, string $key) => [
                    'key' => $key,
                    'count' => $this->segmentQuery($key)->count(),
                    'tone' => $definition['tone'],
                ])
                ->values()
                ->all(),
            'files' => $files->map(fn (CrmChatFile $file) => $this->file($file, withReason: true))->all(),
            'total' => [
                'files' => CrmChatFile::query()->count(),
                'bytes' => (int) CrmChatFile::query()->sum('size'),
                'segmentBytes' => (int) $this->segmentQuery($segment)->sum('size'),
            ],
            'limits' => $this->limits(),
        ];
    }

    /**
     * The segments, each one a rule rather than a checkbox.
     *
     * @return array<string, array{tone: string}>
     */
    public function segments(): array
    {
        return [
            'all' => ['tone' => 'plain'],
            'image' => ['tone' => 'plain'],
            'log' => ['tone' => 'warning'],
            'doc' => ['tone' => 'plain'],
            'lainos' => ['tone' => 'action'],
            'week' => ['tone' => 'plain'],
            'heavy' => ['tone' => 'warning'],
        ];
    }

    /** @return Builder<CrmChatFile> */
    private function segmentQuery(string $segment)
    {
        $query = CrmChatFile::query();

        return match ($segment) {
            'image' => $query->where('kind', 'image'),
            'log' => $query->where('kind', 'log'),
            'doc' => $query->where('kind', 'doc'),
            // Brought by the daemon rather than by a person: it is the one
            // participant whose uploads nobody remembers making.
            'lainos' => $query->whereNull('user_id'),
            'week' => $query->where('created_at', '>=', now()->subWeek()),
            'heavy' => $query->where('size', '>=', 5 * 1024 * 1024),
            default => $query,
        };
    }

    /**
     * How many lines this operator has not seen.
     *
     * Their own lines never count: a badge that lights up because you wrote
     * something is a badge that stops meaning anything.
     */
    public function unreadFor(?User $viewer): int
    {
        if ($viewer === null) {
            return 0;
        }

        return CrmChatMessage::query()
            ->where('id', '>', $this->lastRead($viewer))
            ->where(fn ($query) => $query
                ->whereNull('user_id')
                ->orWhere('user_id', '!=', $viewer->getKey()))
            ->count();
    }

    /** Mark everything up to $id as seen, and stamp presence. */
    public function markRead(User $viewer, ?int $id = null): void
    {
        $id ??= (int) CrmChatMessage::query()->max('id');

        DB::table('crm_chat_reads')->updateOrInsert(
            ['user_id' => $viewer->getKey()],
            ['last_read_id' => max($id, $this->lastRead($viewer)), 'read_at' => now()],
        );
    }

    private function lastRead(User $viewer): int
    {
        return (int) DB::table('crm_chat_reads')
            ->where('user_id', $viewer->getKey())
            ->value('last_read_id');
    }

    /**
     * Who is in the room.
     *
     * Presence is the last time someone's browser asked the room for news —
     * the only fact this server actually has. LainOS is listed as what it is,
     * with the backend that would answer if it were called now.
     *
     * @return list<array<string, mixed>>
     */
    private function people(User $viewer): array
    {
        $reads = DB::table('crm_chat_reads')->pluck('read_at', 'user_id');

        $people = User::crmOperators()
            ->get(['id', 'name'])
            ->map(function (User $operator) use ($reads, $viewer): array {
                $seen = $reads[$operator->getKey()] ?? null;

                return [
                    'id' => $operator->getKey(),
                    'name' => $operator->name,
                    'kind' => 'operator',
                    'you' => $operator->is($viewer),
                    // With its offset, always. The column comes back out of
                    // the driver as a bare "Y-m-d H:i:s" in this server's
                    // zone, and a browser reads a bare stamp as its own local
                    // time — which drew somebody who was typing at that
                    // moment as last seen three hours ago, one whole UTC
                    // offset into the past.
                    'seenAt' => $seen === null
                        ? null
                        : CarbonImmutable::parse((string) $seen)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $status = $this->lainos->status();

        $people[] = [
            'id' => null,
            'name' => 'LainOS',
            'kind' => 'lainos',
            'you' => false,
            'backend' => $status['backend'],
        ];

        return $people;
    }

    /** @return list<array<string, mixed>> */
    private function recentFiles(): array
    {
        return CrmChatFile::query()
            ->with('uploader:id,name')
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (CrmChatFile $file) => $this->file($file))
            ->all();
    }

    /** @return array<string, mixed> */
    private function message(CrmChatMessage $message, User $viewer): array
    {
        return [
            'id' => $message->id,
            'author' => $message->author,
            'name' => $message->isFromLainos()
                ? 'LainOS'
                : ($message->sender?->name ?? '—'),
            'mine' => $message->user_id !== null && $message->user_id === $viewer->getKey(),
            'body' => $message->body,
            'at' => $message->created_at?->toIso8601String(),
            'files' => $message->files->map(fn (CrmChatFile $file) => $this->file($file))->all(),
            'contact' => $message->contact === null ? null : [
                'id' => $message->contact->id,
                'name' => $message->contact->name,
            ],
            'task' => $message->task === null ? null : [
                'id' => $message->task->id,
                'title' => Str::limit($message->task->title, 60),
            ],
            'call' => $message->calls_lainos ? [
                'state' => $message->lainos_state,
                'note' => $message->lainos_note,
                // What was tried and what came back, per attempt.
                'attempts' => $message->meta['attempts'] ?? [],
            ] : null,
            'answer' => $message->isFromLainos() ? ($message->meta ?? []) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function file(CrmChatFile $file, bool $withReason = false): array
    {
        return [
            'id' => $file->id,
            'messageId' => $file->crm_chat_message_id,
            'name' => $file->name,
            'ext' => $file->extension(),
            'kind' => $file->kind,
            'size' => $file->size,
            'by' => $file->user_id === null ? 'LainOS' : ($file->uploader?->name ?? '—'),
            'at' => $file->created_at?->toIso8601String(),
            'reason' => $withReason ? Str::limit((string) $file->message?->body, 90) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function limits(): array
    {
        return [
            'maxMb' => (int) config('crm.chat.files.max_mb', 25),
            'maxFiles' => (int) config('crm.chat.files.max_per_message', 5),
            'maxChars' => (int) config('crm.chat.max_chars', 8000),
            'retentionDays' => (int) config('crm.chat.files.retention_days', 180),
            'contextMessages' => (int) config('crm.chat.lainos.context_messages', 20),
        ];
    }
}
