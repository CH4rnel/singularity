<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use App\Services\Console\ConsoleFeed;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where LainOS files what it did.
 *
 * The daemon runs on the host, unattended and mostly at night: it forges the
 * wishes holders leave it, takes profit on journaled positions, fires the
 * balance watches somebody set weeks ago, brings back research digests. All
 * of that used to exist in exactly one place — a Telegram message that
 * scrolled away — so the board that is supposed to answer "what is this
 * project doing" answered only for the work three people typed in by hand.
 *
 * Two rules make this safe enough to expose, and they are the heartbeat's:
 *
 *   1. A shared token compared in constant time, with an unset token refusing
 *      everything. This route *writes*, so an open one would be a way for
 *      anyone on the internet to put sentences in front of the operators.
 *
 *   2. It accepts facts and never instructions. A record says what happened
 *      and whether it is finished; it cannot assign an operator, cannot set a
 *      deadline someone else has to meet, cannot reach a contact and cannot
 *      edit a task that already exists. The worst a stolen token buys is
 *      noise on the board, which is visible and deletable.
 *
 * The id is the sender's — `lainos:trade:0x…`, `lainos:wish:12` — because a
 * daemon that retried a request whose answer it never saw must not write the
 * same line twice. Repeats are answered with the row that already exists.
 *
 * Status is the whole vocabulary: a record that needs a human arrives `open`
 * and unowned, and lands in the board's "nobody took this" band; a record of
 * something already finished arrives `done` and is a log line under it. There
 * is no third state, because a record nobody has to act on and nobody has
 * finished is a record that should not have been sent.
 */
class CrmTaskIngestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('crm.ingest.token', '');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Crm-Token'))) {
            // 404 and not 401: an endpoint that confirms it exists is an
            // endpoint worth guessing tokens against.
            abort(404);
        }

        $data = $request->validate([
            'id' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:'.(int) config('crm.ingest.max_title', 200)],
            'detail' => ['nullable', 'string', 'max:'.(int) config('crm.ingest.max_detail', 4000)],
            'status' => ['nullable', 'string', 'in:open,done'],
            'priority' => ['nullable', 'string', 'in:'.implode(',', CrmTask::PRIORITIES)],
            // When it happened, which for a finished record is not the same
            // moment as when this request arrived: an outbox that waited out
            // an outage delivers hours late and must still date its own past.
            'at' => ['nullable', 'date'],
        ]);

        $status = $data['status'] ?? 'open';
        $at = isset($data['at']) ? CarbonImmutable::parse($data['at']) : CarbonImmutable::now();
        // A stopped clock on the reporter must not date a row into next year.
        $at = $at->isFuture() ? CarbonImmutable::now() : $at;

        $task = CrmTask::query()->firstOrNew(['external_id' => $data['id']]);
        $created = ! $task->exists;

        if ($created) {
            $task->fill([
                'title' => $data['title'],
                'description' => $data['detail'] ?? null,
                'status' => $status,
                'priority' => $data['priority'] ?? 'normal',
                'completed_at' => $status === 'done' ? $at : null,
            ]);
            // Nobody typed it and nobody owns it yet, and both of those are
            // left null on purpose: the board already draws unowned work as
            // its own band above the columns.
            $task->save();

            ConsoleFeed::forget();
        }

        return response()->json([
            'ok' => true,
            'id' => $task->id,
            'created' => $created,
        ], $created ? 201 : 200);
    }
}
