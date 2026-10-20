<?php

namespace App\Console\Commands;

use App\Models\ContributionRepository;
use App\Services\Contributions\ContributionIndexer;
use App\Services\Contributions\RepositoryVerifier;
use Illuminate\Console\Command;

/**
 * Read new merged work out of every verified repository.
 *
 * Repositories named in `contributions.repositories` are enrolled on the
 * first run and re-verified on every run, so a maintainer who deletes the
 * verification file stops the rewards on the next sweep.
 */
class ContributionsSyncCommand extends Command
{
    protected $signature = 'contributions:sync {repository? : owner/name — only this one}';

    protected $description = 'Index merged pull requests and accepted issues for the open-source reward engine';

    public function handle(RepositoryVerifier $verifier, ContributionIndexer $indexer): int
    {
        foreach ((array) config('contributions.repositories') as $name) {
            ContributionRepository::query()->firstOrCreate(['full_name' => strtolower((string) $name)]);
        }

        $repositories = ContributionRepository::query()
            ->where('active', true)
            ->when($this->argument('repository'), fn ($query, $name) => $query->where('full_name', strtolower((string) $name)))
            ->orderBy('full_name')
            ->get();

        $failed = false;

        foreach ($repositories as $repository) {
            $verifier->verify($repository);

            if ($repository->verified_at === null) {
                $this->line("{$repository->full_name}: not verified ({$repository->verification_error}) — skipped");

                continue;
            }

            $stats = $indexer->sync($repository);
            $failed = $failed || ! $stats['complete'];

            $this->line(sprintf(
                '%s: %d pull requests, %d issues — %d counted, %d refused%s',
                $repository->full_name,
                $stats['pulls'],
                $stats['issues'],
                $stats['counted'],
                $stats['rejected'],
                $stats['complete'] ? '' : ' (incomplete: GitHub did not answer every read; retried next run)',
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
