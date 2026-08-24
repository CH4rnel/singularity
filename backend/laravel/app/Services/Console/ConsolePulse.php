<?php

namespace App\Services\Console;

use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Has anything I am looking at changed?" — the console's own heartbeat.
 *
 * Three operators read the same five lenses at the same time; that is the
 * whole point of the console. Until this existed only the room refreshed
 * itself, so a task claimed on one desk stayed unclaimed on the other until
 * somebody pressed F5 — and a status board that lies until it is reloaded is
 * a status board nobody trusts twice.
 *
 * The mechanism is a poll and deliberately not a socket. A push would need a
 * process of its own (Reverb, or an SSE loop holding a PHP-FPM worker per
 * open tab), and this host is the one whose scheduler was dormant for months
 * — a liveness that depends on a daemon nobody watches is a liveness that
 * ends silently. Three operators asking one cheap question every few seconds
 * is a load this server does not notice, and it fails the honest way: the
 * answer simply does not arrive, and the top bar says so.
 *
 * What comes back is a **version per lens** and nothing else. Each one is an
 * opaque string the browser only ever compares with the one it holds; when it
 * differs, that lens re-reads its own props through Inertia. So the payload
 * stays a few hundred bytes no matter how much happened, and the page that is
 * not on screen costs nothing.
 */
class ConsolePulse
{
    public function __construct(private ChatRoom $chat) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?User $viewer): array
    {
        return [
            // The server's own clock, so a page can tell how old its last
            // answer is without trusting the reader's machine.
            'at' => now()->toIso8601String(),
            'v' => [
                'now' => $this->queue(),
                // The board and what is said on it: a comment left by one
                // operator is the board changing for the other, and it lands
                // in a table of its own.
                'tasks' => $this->stamp('crm_tasks').':'.$this->stamp('crm_task_comments'),
                'people' => $this->stamp('crm_contacts'),
                'notes' => $this->stamp('crm_notes'),
                'chat' => $this->stamp('crm_chat_messages'),
                'files' => $this->stamp('crm_chat_files'),
                'machines' => $this->machines(),
            ],
            'counts' => $this->counts($viewer),
        ];
    }

    /**
     * The rail's badges, live on every lens.
     *
     * `attention` is read out of the queue's cache and never rebuilt here: a
     * poll that recomputes a thirty-day aggregate every few seconds is how a
     * console becomes the reason the database is busy. A cold cache answers
     * `null` — unknown, which the rail keeps rather than drawing as zero,
     * because a badge that reads 0 because nobody asked is the same lie as a
     * green light on a dead sensor.
     *
     * @return array<string, int|null>
     */
    private function counts(?User $viewer): array
    {
        $queue = Cache::get(ConsoleFeed::CACHE_KEY);

        return [
            'attention' => is_array($queue) ? count($queue['attention'] ?? []) : null,
            'tasks' => CrmTask::query()->overdue()->count(),
            'chat' => $this->chat->unreadFor($viewer),
        ];
    }

    /**
     * A version of one table: how many rows, and when one of them last moved.
     *
     * Count and high-water mark together, because either alone misses half of
     * what happens — an edit leaves the count where it was, a delete leaves
     * the newest `updated_at` where it was. Both together move on every
     * insert, update and delete, which is all a version has to promise.
     */
    private function stamp(string $table): string
    {
        /*
         * The resolution is one second, because that is what these columns
         * keep. Two different rows written inside the same second, with a
         * beat landing between them, can leave this string where it was —
         * which is why the room does not depend on it (`useConsoleBeat`) and
         * asks the server what changed since its own last read instead. For a
         * board and a list, one beat of lateness is not a lie worth building
         * a change table for.
         */
        $row = DB::table($table)
            ->selectRaw('count(*) as rows_count, max(updated_at) as last_at')
            ->first();

        return ((int) ($row->rows_count ?? 0)).':'.((string) ($row->last_at ?? '—'));
    }

    /**
     * The queue's material, rather than the queue.
     *
     * "Сейчас" is a cached derivative of six sources; recomputing it to find
     * out whether it changed would cost exactly what computing it costs. So
     * this stamps the rows underneath — incidents, the latest sweep, what is
     * asleep, the tasks — and lets the lens re-read itself when one of them
     * moves. The sweep alone moves every five minutes, which is the floor on
     * how stale an open queue can get, and matches the rate the queue is
     * collected at anyway.
     */
    private function queue(): string
    {
        $incidents = DB::table('service_incidents')
            ->whereNull('resolved_at')
            ->selectRaw('count(*) as rows_count, max(updated_at) as last_at')
            ->first();

        return implode(':', [
            (int) ($incidents->rows_count ?? 0),
            (string) ($incidents->last_at ?? '—'),
            // The primary key rather than count(*): every sweep writes a row
            // per service, so this table is the long one, and its newest id
            // answers the same question for free.
            (int) DB::table('service_checks')->max('id'),
            $this->stamp('console_snoozes'),
            $this->stamp('crm_tasks'),
        ]);
    }

    /** What "Машины" is drawn from: the sweep, the incidents, the hosts. */
    private function machines(): string
    {
        return implode(':', [
            (int) DB::table('service_checks')->max('id'),
            $this->stamp('service_incidents'),
            (int) DB::table('service_heartbeats')->max('id'),
        ]);
    }
}
