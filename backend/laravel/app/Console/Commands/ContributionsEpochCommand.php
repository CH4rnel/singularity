<?php

namespace App\Console\Commands;

use App\Models\ContributionEpoch;
use App\Services\Contributions\EpochBuilder;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Build an epoch's tree, or record that its root went on chain.
 *
 *   contributions:epoch build [N]          — the next closed epoch, or rebuild N
 *   contributions:epoch published N 0x…    — the root is on chain; claims open
 *   contributions:epoch show [N]
 *
 * Publishing itself is `crypto/hardhat/scripts/contributions-publish-epoch.ts`,
 * run by whoever holds the contract's owner key; this server never holds it.
 */
class ContributionsEpochCommand extends Command
{
    protected $signature = 'contributions:epoch {action=show : build|published|show} {number?} {tx?}';

    protected $description = 'Build, publish or show an open-source reward epoch';

    public function handle(EpochBuilder $builder): int
    {
        $number = $this->argument('number') !== null ? (int) $this->argument('number') : null;

        try {
            $epoch = match ($this->argument('action')) {
                'build' => $builder->build($number),
                'published' => $builder->markPublished((int) $number, (string) $this->argument('tx')),
                'show' => $number !== null
                    ? ContributionEpoch::query()->where('number', $number)->firstOrFail()
                    : ContributionEpoch::query()->orderByDesc('number')->first(),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($epoch === null) {
            $next = $builder->nextPeriod();
            $this->line("No epoch yet. Epoch {$next['number']} runs {$next['starts_at']} → {$next['ends_at']} UTC.");

            return self::SUCCESS;
        }

        $scale = BigDecimal::ten()->power($epoch->decimals);

        $this->table(['epoch', 'period (UTC)', 'status', 'pool', 'allocated', 'leaves', 'root'], [[
            $epoch->number,
            $epoch->starts_at->toDateString().' → '.$epoch->ends_at->toDateString(),
            $epoch->status,
            (string) BigDecimal::of($epoch->pool_raw)->exactlyDividedBy($scale),
            (string) BigDecimal::of($epoch->allocated_raw)->exactlyDividedBy($scale),
            $epoch->rewards()->count(),
            $epoch->merkle_root ?? '—',
        ]]);

        if ($epoch->status === ContributionEpoch::BUILT && $epoch->merkle_root !== null) {
            $this->line('Publish with:');
            $this->line("  cd crypto/hardhat && EPOCH={$epoch->number} ROOT={$epoch->merkle_root} TOTAL={$epoch->allocated_raw} npx tsx scripts/contributions-publish-epoch.ts");
        }

        return self::SUCCESS;
    }
}
