<?php

namespace App\Services\Contributions;

use App\Models\Contribution;

/**
 * Points for one contribution, in hundredths. Pure: config in, number out.
 *
 * Size is on a log curve because the cheapest way to look productive is to
 * commit a lockfile, and the second cheapest is to split one change into ten
 * pull requests — so ten times the lines earns well under twice the points,
 * the curve stops at a ceiling, and anything below `min_changed_lines` is a
 * typo fix that scores nothing at all.
 */
class ContributionScorer
{
    /**
     * @param  array<string, mixed>  $points  config('contributions.points')
     */
    public function __construct(private readonly array $points) {}

    public static function fromConfig(): self
    {
        return new self((array) config('contributions.points'));
    }

    /**
     * @param  list<string>  $labels
     */
    public function kindOfPullRequest(string $title, array $labels): string
    {
        $docLabels = array_map('strtolower', (array) ($this->points['documentation_labels'] ?? []));

        foreach ($labels as $label) {
            if (in_array(strtolower($label), $docLabels, true)) {
                return Contribution::DOCUMENTATION;
            }
        }

        return preg_match('/^docs?\b/i', ltrim($title)) === 1
            ? Contribution::DOCUMENTATION
            : Contribution::PULL_REQUEST;
    }

    public function isTrivial(string $kind, int $additions, int $deletions): bool
    {
        if ($kind === Contribution::ISSUE) {
            return false;
        }

        return $additions + $deletions < (int) ($this->points['min_changed_lines'] ?? 5);
    }

    /**
     * Hundredths of a point; zero for trivial work.
     */
    public function score(string $kind, int $additions, int $deletions, float $repositoryWeight = 1.0): int
    {
        if ($this->isTrivial($kind, $additions, $deletions)) {
            return 0;
        }

        $base = (float) ($this->points[$kind] ?? 0);

        if ($kind === Contribution::ISSUE) {
            return (int) round($base * $repositoryWeight * 100);
        }

        $unit = max(1, (int) ($this->points['size_unit_lines'] ?? 100));
        $ceiling = (float) ($this->points['max_size_multiplier'] ?? 3.0);
        $multiplier = min($ceiling, 1 + log(1 + ($additions + $deletions) / $unit, 2));

        return (int) round($base * $multiplier * $repositoryWeight * 100);
    }
}
