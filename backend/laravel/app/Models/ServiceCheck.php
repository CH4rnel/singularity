<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One service, as one sweep found it.
 *
 * Rows are the uptime denominator, so they are written for healthy services
 * too — an uptime figure computed only from failures is a figure with no
 * denominator, and every dashboard that has ever tried it reported 0% or 100%.
 */
class ServiceCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service',
        'status',
        'latency_ms',
        'detail',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * The id of the newest check per service.
     *
     * Two index-backed queries instead of materialising a day of rows and
     * discarding all but the last of each: a sweep writes one row per service
     * every five minutes, so a day is thousands of rows carrying a JSON
     * document apiece, and both the board and every sweep want the same few
     * dozen of them.
     *
     * The window bounds the group-by and is deliberately generous. Anything
     * older means the monitor itself stopped, which the board shows through
     * each check's own age rather than by pretending the service is fine.
     *
     * @return Collection<int, int>
     */
    public static function latestIds(): Collection
    {
        return DB::table('service_checks')
            ->selectRaw('max(id) as id')
            ->where('checked_at', '>=', CarbonImmutable::now()->subDay())
            ->groupBy('service')
            ->pluck('id');
    }
}
