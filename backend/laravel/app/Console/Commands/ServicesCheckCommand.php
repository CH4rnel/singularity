<?php

namespace App\Console\Commands;

use App\Services\Monitoring\ServiceBoard;
use App\Services\Monitoring\ServiceMonitor;
use App\Services\Monitoring\ServiceRegistry;
use App\Services\Monitoring\ServiceStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One sweep of everything in config/monitoring.php.
 *
 * Reads only — it probes, records and reports, and cannot restart, fund or
 * repair anything. It runs unattended every five minutes, which is exactly the
 * kind of job that must not be able to act on what it finds.
 *
 * `--alert` is what the scheduler passes. By hand it is usually run without
 * it: seeing the board should not page anyone.
 */
#[Signature('services:check {--alert : Report state changes to the operator} {--usage : Also print who is using what}')]
#[Description('Probe every service in the registry and record what it found')]
class ServicesCheckCommand extends Command
{
    public function handle(
        ServiceMonitor $monitor,
        ServiceRegistry $registry,
        ServiceBoard $board,
    ): int {
        if (! config('monitoring.enabled', true)) {
            $this->warn('Monitoring is off (MONITORING_ENABLED=false).');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $results = $monitor->sweep(alert: (bool) $this->option('alert'));
        $elapsed = round(microtime(true) - $started, 1);

        $rows = [];

        foreach ($registry->grouped() as $group => $definitions) {
            foreach ($definitions as $definition) {
                $result = $results[$definition->key] ?? null;
                $status = $result?->status ?? ServiceStatus::Unknown;

                $rows[] = [
                    $group,
                    $definition->label,
                    $status->emoji().' '.$status->value,
                    $result?->reason ?? '',
                    $result?->latencyMs === null ? '' : $result->latencyMs.' ms',
                ];
            }
        }

        $this->table(['Group', 'Service', 'Status', 'Reason', 'Latency'], $rows);

        $counts = array_count_values(array_map(
            fn ($result) => $result->status->value,
            $results,
        ));

        $this->line(sprintf(
            '%d services in %ss — %s',
            count($results),
            $elapsed,
            collect(['down', 'degraded', 'unknown', 'off', 'up'])
                ->filter(fn (string $status) => ($counts[$status] ?? 0) > 0)
                ->map(fn (string $status) => ($counts[$status] ?? 0).' '.$status)
                ->implode(', '),
        ));

        if ($this->option('usage')) {
            $this->usageTable($board);
        }

        // A sweep that ran is a success even when everything it found is on
        // fire: a non-zero exit would make the scheduler's own log the place
        // outages are reported, which is nobody's inbox.
        return self::SUCCESS;
    }

    private function usageTable(ServiceBoard $board): void
    {
        $data = $board->build();

        $this->newLine();
        $this->line('<comment>Usage</comment>');

        $rows = [];

        foreach ($data['services'] as $service) {
            $usage = $service['usage'] ?? null;

            if ($usage === null || ! $usage['measured']) {
                continue;
            }

            $rows[] = [
                $service['label'],
                $usage['count_7d'],
                $usage['count_30d'],
                $usage['actors_30d'] ?? '—',
                $usage['idle_days'] === null ? 'never' : $usage['idle_days'].'d ago',
            ];
        }

        $this->table(['Service', '7d', '30d', 'Actors 30d', 'Last used'], $rows);

        $idle = $data['idle'];

        if ($idle !== []) {
            $this->newLine();
            $this->warn('Nothing used these in 30 days:');

            foreach ($idle as $service) {
                $this->line('  • '.$service['label'].' — '
                    .($service['usage']['idle_days'] === null
                        ? 'never used'
                        : 'last used '.$service['usage']['idle_days'].' days ago'));
            }
        }

        $unmeasured = array_filter(
            $data['services'],
            fn (array $service) => ! ($service['usage']['measured'] ?? false),
        );

        if ($unmeasured !== []) {
            $this->newLine();
            $this->line('<comment>Usage not measurable from here ('.count($unmeasured).')</comment>: '
                .implode(', ', array_column($unmeasured, 'key')));
        }
    }
}
