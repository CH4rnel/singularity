<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether anybody is using each service.
 *
 * The other half of the board, and the half that answers a different kind of
 * question. Health says "the launchpad responds"; this says "nobody has
 * launched anything in six weeks", and only one of those two facts ever leads
 * to a decision about the product.
 *
 * Three outcomes, and the middle one is the point:
 *
 *   used        someone did the thing recently
 *   unused      the table exists, is readable, and is empty for the window
 *   unmeasured  this app cannot tell, and says so
 *
 * Collapsing `unmeasured` into `unused` would quietly condemn the explorer,
 * the RPC and the DEX — three of the most used things here — because their
 * usage lives in someone else's access log. A dashboard that recommends
 * deleting them is worse than no dashboard.
 */
class ServiceUsageService
{
    public function __construct(private ServiceRegistry $registry) {}

    /**
     * @return array<string, array{
     *     measured: bool,
     *     last_at: string|null,
     *     idle_days: int|null,
     *     count_7d: int|null,
     *     count_30d: int|null,
     *     actors_30d: int|null,
     *     unit: string|null,
     * }>
     */
    public function all(): array
    {
        $usage = [];

        foreach ($this->registry->all() as $key => $definition) {
            $usage[$key] = $this->forService($definition);
        }

        return $usage;
    }

    /** @return array<string, mixed> */
    public function forService(ServiceDefinition $definition): array
    {
        $spec = $definition->usage;

        if ($spec === null) {
            return $this->unmeasured();
        }

        $table = (string) ($spec['table'] ?? '');
        $column = (string) ($spec['column'] ?? 'created_at');

        // A registry entry pointing at a table that was renamed must read as
        // "cannot tell", not as "nobody used it" — the second is a claim, and
        // this code has no grounds for it.
        if ($table === '' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return $this->unmeasured();
        }

        $now = CarbonImmutable::now();

        $last = $this->query($spec)->max($column);
        $lastAt = $last === null ? null : CarbonImmutable::parse($last);

        $actors = null;
        $distinct = $spec['distinct'] ?? null;

        if (is_string($distinct) && Schema::hasColumn($table, $distinct)) {
            $actors = $this->query($spec)
                ->where($column, '>=', $now->subDays(30))
                ->distinct()
                ->count($distinct);
        }

        return [
            'measured' => true,
            'last_at' => $lastAt?->toIso8601String(),
            'idle_days' => $lastAt === null ? null : (int) $lastAt->diffInDays($now, true),
            'count_7d' => $this->query($spec)->where($column, '>=', $now->subDays(7))->count(),
            'count_30d' => $this->query($spec)->where($column, '>=', $now->subDays(30))->count(),
            'actors_30d' => $actors,
            'unit' => $table,
        ];
    }

    /** @param array<string, mixed> $spec */
    private function query(array $spec): Builder
    {
        $query = DB::table((string) $spec['table']);

        foreach ((array) ($spec['where'] ?? []) as $column => $value) {
            $query->where($column, $value);
        }

        if (is_string($spec['where_not_null'] ?? null)) {
            $query->whereNotNull($spec['where_not_null']);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function unmeasured(): array
    {
        return [
            'measured' => false,
            'last_at' => null,
            'idle_days' => null,
            'count_7d' => null,
            'count_30d' => null,
            'actors_30d' => null,
            'unit' => null,
        ];
    }
}
