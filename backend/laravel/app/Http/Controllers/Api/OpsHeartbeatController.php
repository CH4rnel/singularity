<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the host tells this app about itself.
 *
 * Laravel runs in a container. The docker daemon, the tmux sessions holding
 * the Telegram bot and LainOS, the load average and the disk are all outside
 * it — which is to say that the majority of this project's moving parts are
 * invisible from inside the only process that could report on them. So the
 * host pushes, once a minute, from `scripts/ops/heartbeat.sh`.
 *
 * Two rules make this safe enough to expose on the public internet:
 *
 *   1. A shared token, compared in constant time, and no token configured
 *      means the endpoint refuses everything. An open heartbeat is worse than
 *      none, because anyone could declare a dead host healthy and the board
 *      would print it with the same confidence as the truth.
 *
 *   2. It accepts facts and never instructions. The body says which
 *      containers exist and how loaded the box is; what any of that *means*
 *      is decided by config/monitoring.php on this side. A compromised
 *      reporter can lie about the host — it cannot make this app run
 *      anything, and cannot invent a service that is not in the registry.
 */
class OpsHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('monitoring.heartbeat.token', '');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Ops-Token'))) {
            // 404 rather than 401: an endpoint that confirms it exists is an
            // endpoint worth guessing tokens against.
            abort(404);
        }

        $data = $request->validate([
            'host' => ['required', 'string', 'max:128'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'cpus' => ['nullable', 'integer', 'min:1', 'max:1024'],
            'load' => ['nullable', 'array', 'size:3'],
            'load.*' => ['numeric'],

            'memory' => ['nullable', 'array:total_mb,available_mb'],
            'memory.*' => ['numeric'],
            'swap' => ['nullable', 'array:total_mb,used_mb'],
            'swap.*' => ['numeric'],
            'disk' => ['nullable', 'array:path,used_percent,free_gb'],
            'disk.path' => ['nullable', 'string', 'max:128'],
            'disk.used_percent' => ['nullable', 'numeric'],
            'disk.free_gb' => ['nullable', 'numeric'],

            // Bounded so one report cannot become a way of filling this
            // server's disk from the host's.
            'containers' => ['nullable', 'array', 'max:100'],
            'containers.*.name' => ['required', 'string', 'max:128'],
            'containers.*.state' => ['required', 'string', 'max:32'],
            'containers.*.status' => ['nullable', 'string', 'max:128'],
            'containers.*.restarts' => ['nullable', 'integer', 'min:0'],

            'tmux' => ['nullable', 'array', 'max:50'],
            'tmux.*' => ['string', 'max:64'],

            'processes' => ['nullable', 'array', 'max:50'],
            'processes.*' => ['integer', 'min:0'],

            // systemd's own word for each unit — `active`, `failed`,
            // `activating`, `inactive`. Not normalised here: the supervisor's
            // vocabulary is richer than a boolean, and `activating` in a
            // restart loop is a different fact from `inactive`.
            'units' => ['nullable', 'array', 'max:50'],
            'units.*' => ['string', 'max:32'],

            'crons' => ['nullable', 'array', 'max:50'],
            'crons.*.log_age_seconds' => ['nullable', 'integer', 'min:0'],
            'crons.*.log_size_mb' => ['nullable', 'numeric', 'min:0'],
        ]);

        // One row per host, overwritten. A heartbeat is a snapshot of now; its
        // history is already recorded as the service_checks it produced, and
        // keeping every minute of it would be a second, worse copy.
        ServiceHeartbeat::query()->updateOrCreate(
            ['host' => $data['host']],
            [
                'payload' => $data,
                // The server's clock, not the host's: a reporter with a wrong
                // clock would otherwise look permanently stale or
                // permanently fresh, and staleness is the whole signal.
                'reported_at' => CarbonImmutable::now(),
            ],
        );

        return response()->json(['ok' => true]);
    }
}
