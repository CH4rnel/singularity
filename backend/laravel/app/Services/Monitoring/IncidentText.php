<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;

/**
 * What an incident says to the person reading it on a phone, in Russian.
 *
 * The alert used to print the first four scalar keys of whatever a probe
 * returned, which for the host meant "cpus 4 · uptime seconds 2054377" — the
 * two facts nobody needs — while the load, the memory and the disk sat in
 * arrays the printer skipped. And a container in a crash loop said "Restarting
 * (127)" for three weeks without once saying why. So this knows what the
 * common probes' details *mean*: the host's load per core and who is eating
 * it, a container's exit code and the last line it printed. Anything it does
 * not recognise is still printed, the old way.
 *
 * Plain text in, plain text out: callers escape for Telegram's HTML, so the
 * daily report and the alert can share every sentence.
 */
final class IncidentText
{
    /** Probe reason codes as a phrase. An unknown code is printed as it is. */
    private const REASONS = [
        'unreachable' => 'не отвечает',
        'bad-status' => 'отвечает ошибкой',
        'bad-rpc-response' => 'RPC отвечает не то',
        'bad-api-response' => 'API отвечает не то',
        'unexpected-body' => 'отвечает не то',
        'wrong-chain' => 'отвечает другой сетью',
        'stale-head' => 'сеть не производит блоки',
        'slow-head' => 'блоки идут медленно',
        'slow' => 'отвечает медленно',
        'crash-loop' => 'контейнер падает и перезапускается по кругу',
        'restarted' => 'контейнер перезапустился',
        'container-paused' => 'контейнер на паузе',
        'container-exited' => 'контейнер остановлен',
        'container-dead' => 'контейнер мёртв',
        'container-created' => 'контейнер создан, но не запущен',
        'tmux-missing' => 'tmux-сессии нет — процесс умер',
        'process-missing' => 'процесс не найден',
        'unit-failed' => 'systemd-юнит упал',
        'unit-inactive' => 'systemd-юнит остановлен',
        'cron-silent' => 'крон перестал писать лог',
        'log-unrotated' => 'лог растёт без ротации',
        'scheduler-stalled' => 'планировщик Laravel не запускается',
        'queue-stalled' => 'очередь стоит',
        'queue-backlog' => 'очередь копится',
        'jobs-failing' => 'задачи падают',
        'command-failing' => 'команда по расписанию падает',
        'command-stale' => 'команда по расписанию давно не запускалась',
        'table-stale' => 'данные перестали обновляться',
        'index-lag' => 'индексатор отстаёт',
        'heartbeat-stale' => 'хост перестал присылать heartbeat',
        'never-reported' => 'ни разу не отчитался',
        'host-pressure' => 'хост перегружен',
        'certificate-expired' => 'TLS-сертификат истёк',
        'certificate-expiring' => 'TLS-сертификат скоро истечёт',
        'tls-unreadable' => 'TLS-сертификат не читается',
        'cache-mismatch' => 'кэш расходится с источником',
        'tank-low' => 'бак газ-станции почти пуст',
        'relayer-low' => 'у релеера кончается газ',
        'daemon-stalled' => 'нода кошелька не двигается',
    ];

    /** Exit codes whose meaning is not in the log line itself. */
    private const EXIT_CODES = [
        126 => 'код 126: файл в образе не запускается',
        127 => 'код 127: в образе не хватает команды или библиотеки',
        137 => 'код 137: убит — чаще всего кончилась память',
        139 => 'код 139: segfault',
    ];

    /** Detail keys that are bookkeeping, not facts anyone acts on. */
    private const NOISE = ['uptime_seconds', 'cpus', 'problems', 'reason', 'restarts_since_last_check'];

    public static function status(ServiceStatus $status): string
    {
        return match ($status) {
            ServiceStatus::Down => 'лежит',
            ServiceStatus::Degraded => 'работает с перебоями',
            ServiceStatus::Unknown => 'не видно',
            ServiceStatus::Off => 'выключен',
            ServiceStatus::Up => 'работает',
        };
    }

    public static function reason(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return '';
        }

        if (isset(self::REASONS[$reason])) {
            return self::REASONS[$reason];
        }

        // `unit-activating`, `container-removing`: the prefix carries the
        // meaning and the rest is the supervisor's own word.
        foreach (['unit-' => 'systemd-юнит: ', 'container-' => 'контейнер: '] as $prefix => $phrase) {
            if (str_starts_with($reason, $prefix)) {
                return $phrase.substr($reason, strlen($prefix));
            }
        }

        return $reason;
    }

    /**
     * The facts worth a line each, most useful first.
     *
     * @param  array<string, mixed>  $detail
     * @return array<int, string>
     */
    public static function facts(array $detail): array
    {
        if (is_array($detail['load'] ?? null)) {
            return self::host($detail);
        }

        if (isset($detail['container'])) {
            return self::container($detail);
        }

        $line = self::scalars($detail);

        return $line === '' ? [] : [$line];
    }

    /** "12 мин", "3.4 ч", "24 дн." */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 90 => $seconds.' с',
            $seconds < 5400 => round($seconds / 60).' мин',
            $seconds < 172800 => round($seconds / 3600, 1).' ч',
            default => round($seconds / 86400).' дн.',
        };
    }

    /**
     * The machine's load per core, its memory, swap and disk, and the
     * containers eating it.
     *
     * @param  array<string, mixed>  $detail
     * @return array<int, string>
     */
    public static function host(array $detail): array
    {
        $parts = [];
        $cpus = max(1, (int) ($detail['cpus'] ?? 1));

        if (is_array($detail['load'] ?? null)) {
            $load = (float) ($detail['load'][1] ?? $detail['load'][0] ?? 0);
            $parts[] = 'нагрузка '.self::number($load).' на '.$cpus.' '.self::plural($cpus, 'ядро', 'ядра', 'ядер')
                .' ('.self::number($load / $cpus).' на ядро)';
        }

        $memory = is_array($detail['memory'] ?? null) ? $detail['memory'] : null;

        if ($memory !== null && (float) ($memory['total_mb'] ?? 0) > 0) {
            $parts[] = 'свободно '.self::number((float) ($memory['available_mb'] ?? 0) / 1024)
                .' из '.self::number((float) $memory['total_mb'] / 1024).' ГБ памяти';
        }

        $swap = is_array($detail['swap'] ?? null) ? $detail['swap'] : null;

        if ($swap !== null && (float) ($swap['total_mb'] ?? 0) > 0) {
            $used = (float) ($swap['used_mb'] ?? 0);
            $total = (float) $swap['total_mb'];

            if ($used >= $total * 0.95) {
                $parts[] = 'своп заполнен';
            } elseif ($used > 0) {
                $parts[] = 'своп '.round($used).' из '.round($total).' МБ';
            }
        }

        $disk = is_array($detail['disk'] ?? null) ? $detail['disk'] : null;

        if ($disk !== null && isset($disk['used_percent'])) {
            $parts[] = 'диск '.round((float) $disk['used_percent']).'%'
                .(isset($disk['free_gb']) ? ' (свободно '.round((float) $disk['free_gb']).' ГБ)' : '');
        }

        $lines = $parts === [] ? [] : [implode(' · ', $parts)];
        $top = self::top($detail['top'] ?? null);

        if ($top !== null) {
            $lines[] = $top;
        }

        return $lines;
    }

    /** "больше всего CPU: backend 171%, db 90%", or null when nobody is busy. */
    public static function top(mixed $top): ?string
    {
        if (! is_array($top)) {
            return null;
        }

        $busy = [];

        foreach ($top as $row) {
            if (! is_array($row) || ! isset($row['name']) || (float) ($row['cpu'] ?? 0) < 5) {
                continue;
            }

            $busy[] = $row['name'].' '.round((float) $row['cpu']).'%';

            if (count($busy) === 3) {
                break;
            }
        }

        return $busy === [] ? null : 'больше всего CPU: '.implode(', ', $busy);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<int, string>
     */
    private static function container(array $detail): array
    {
        // Docker's status line ends in how long ago, which is stale the moment
        // it is read and is already said by the incident's own duration.
        // "Restarting (127) 21 seconds ago", "Exited (1) Less than a second ago".
        $status = trim((string) preg_replace('/^(.*?\(\d+\))\s+.*\bago$/i', '$1', (string) ($detail['status'] ?? '')));
        $parts = ['контейнер '.$detail['container'].($status !== '' ? ': '.$status : '')];

        if ((int) ($detail['restarts'] ?? 0) > 0) {
            $parts[] = 'перезапусков: '.number_format((int) $detail['restarts'], 0, '.', ' ');
        }

        $code = isset($detail['exit_code']) ? (int) $detail['exit_code'] : null;

        if ($code !== null && isset(self::EXIT_CODES[$code])) {
            $parts[] = self::EXIT_CODES[$code];
        }

        $lines = [implode(' · ', $parts)];

        if (is_string($detail['last_log'] ?? null) && trim($detail['last_log']) !== '') {
            $lines[] = '› '.trim($detail['last_log']);
        }

        return $lines;
    }

    /**
     * Whatever else a probe reported, as one line, the way the alert always
     * printed it: four scalars at most, and a timestamp as how long ago.
     *
     * @param  array<string, mixed>  $detail
     */
    private static function scalars(array $detail): string
    {
        $parts = [];

        foreach ($detail as $key => $value) {
            if (in_array($key, self::NOISE, true) || ! is_scalar($value) || $value === '' || count($parts) >= 4) {
                continue;
            }

            $label = str_replace('_', ' ', (string) $key);

            if (is_string($value) && str_ends_with((string) $key, '_since')) {
                try {
                    $at = CarbonImmutable::parse($value)->utc();
                    $value = $at->format('d.m H:i').' UTC ('.self::duration((int) $at->diffInSeconds(CarbonImmutable::now())).' назад)';
                } catch (\Throwable) {
                    // Not a date after all; print it as it came.
                }
            } elseif (is_bool($value)) {
                $value = $value ? 'да' : 'нет';
            }

            $parts[] = $label.' '.$value;
        }

        return implode(' · ', $parts);
    }

    private static function number(float $value): string
    {
        return (string) round($value, $value >= 10 ? 0 : 1);
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => $one,
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $few,
            default => $many,
        };
    }
}
