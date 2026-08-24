<?php

use App\Services\Monitoring\ScheduledTaskLog;
use App\Services\Monitoring\ServiceMonitor;
use App\Services\Monitoring\ServiceStatus;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Did the scheduler run, and did this command run."
 *
 * Two different questions, and the project has already been caught out by the
 * first: the host cron called a `php` that existed only inside the container,
 * so every scheduled command lay dormant for months while each one
 * individually looked merely idle.
 *
 * Recorded by listening to Laravel's own scheduler events, so a command added
 * tomorrow is monitored the moment it is scheduled.
 */
beforeEach(function () {
    Cache::flush();
});

function scheduledEvent(string $command, int $exitCode = 0): ScheduledEvent
{
    $event = new ScheduledEvent(app(CacheEventMutex::class), $command);
    $event->exitCode = $exitCode;

    return $event;
}

it('records a command from the full command line the scheduler reports', function () {
    // This is the shape Laravel actually emits: the php binary, the artisan
    // path, quoting and the output redirection all included.
    event(new ScheduledTaskFinished(
        scheduledEvent("'/usr/local/bin/php' 'artisan' predictions:resolve > '/dev/null' 2>&1"),
        0.42,
    ));

    $record = ScheduledTaskLog::last('predictions:resolve');

    expect($record)->not->toBeNull()
        ->and($record['command'])->toBe('predictions:resolve')
        ->and($record['ok'])->toBeTrue()
        // Laravel reports the runtime in seconds; the log stores milliseconds.
        ->and($record['runtime_ms'])->toBe(420);
});

it('separates the scheduler running from the command succeeding', function () {
    event(new ScheduledTaskFailed(
        scheduledEvent("'php' 'artisan' dex:apr", 1),
        new RuntimeException('boom'),
    ));

    // The scheduler is demonstrably alive — it is the thing that ran the
    // command that failed.
    expect(ScheduledTaskLog::lastSchedulerRun())->not->toBeNull()
        ->and(ScheduledTaskLog::last('dex:apr')['ok'])->toBeFalse();
});

it('reports a command that has never been seen as unknown, not down', function () {
    config()->set('monitoring.services', [
        'oracle' => [
            'group' => 'onchain',
            'label' => 'Oracle',
            'check' => ['type' => 'scheduled-command', 'command' => 'predictions:resolve'],
            'usage' => null,
        ],
    ]);

    Http::fake();

    // A flushed cache and a command that never ran look identical from here,
    // and only one of them is a fault.
    expect(app(ServiceMonitor::class)->sweep(false)['oracle']->status)
        ->toBe(ServiceStatus::Unknown);
});

it('calls a command that keeps exiting non-zero down', function () {
    config()->set('monitoring.services', [
        'oracle' => [
            'group' => 'onchain',
            'label' => 'Oracle',
            'check' => ['type' => 'scheduled-command', 'command' => 'predictions:resolve'],
            'usage' => null,
        ],
    ]);

    Http::fake();
    ScheduledTaskLog::record('predictions:resolve', ok: false);

    expect(app(ServiceMonitor::class)->sweep(false)['oracle']->reason)
        ->toBe('command-failing');
});

it('calls a scheduler that has stopped entirely down', function () {
    config()->set('monitoring.services', [
        'scheduler' => [
            'group' => 'infra',
            'label' => 'Scheduler',
            'check' => ['type' => 'scheduler', 'stale_seconds' => 900],
            'usage' => null,
        ],
    ]);

    Http::fake();
    ScheduledTaskLog::touchScheduler();

    expect(app(ServiceMonitor::class)->sweep(false)['scheduler']->status)
        ->toBe(ServiceStatus::Up);

    $this->travel(2)->hours();

    expect(app(ServiceMonitor::class)->sweep(false)['scheduler']->reason)
        ->toBe('scheduler-stalled');
});
