<?php

namespace App\Services\Contributions;

use App\Models\Contribution;
use Carbon\CarbonInterface;

/**
 * Whether one contribution counts — the anti-spam and anti-sybil half that
 * the whitepaper says has to ship before anything else in phase X does.
 *
 * Pure: everything it decides on is handed to it, so every refusal is a test.
 * It answers with a reason in one fixed vocabulary, which is what lands on the
 * row and on the contributor's page:
 *
 *   bot            — a GitHub App or a `[bot]` login
 *   excluded       — on the project's own excluded list (the paid core team)
 *   banned         — an operator banned this account
 *   accountTooNew  — the account was younger than the floor when it contributed;
 *                    accounts are free to make and impossible to age
 *   selfMerged     — merged by its own author, so reviewed by nobody
 *   notAccepted    — an issue that was not closed as completed with an
 *                    accepting label
 *   trivial        — below the size floor
 *   dailyCap       — over the per-author, per-repository, per-day count
 *
 * What it cannot see is whether two GitHub accounts are one person. That is
 * held by the rest of the pipeline instead: one GitHub account links to one
 * site account (LinkSocialIdentity), rewards aggregate per address, and no
 * address takes more than `pool.max_share_bps` of an epoch.
 */
class SybilGuard
{
    public const BOT = 'bot';

    public const EXCLUDED = 'excluded';

    public const BANNED = 'banned';

    public const ACCOUNT_TOO_NEW = 'accountTooNew';

    public const SELF_MERGED = 'selfMerged';

    public const NOT_ACCEPTED = 'notAccepted';

    public const TRIVIAL = 'trivial';

    public const DAILY_CAP = 'dailyCap';

    /**
     * @param  array<string, mixed>  $rules  config('contributions.sybil')
     */
    public function __construct(
        private readonly array $rules,
        private readonly ContributionScorer $scorer,
        private readonly array $issueLabels = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (array) config('contributions.sybil'),
            ContributionScorer::fromConfig(),
            (array) config('contributions.points.issue_labels', []),
        );
    }

    /**
     * @param  array{
     *     kind: string,
     *     login: string,
     *     account_type: string,
     *     account_created_at: ?CarbonInterface,
     *     banned: bool,
     *     accepted_at: CarbonInterface,
     *     merged_by: ?string,
     *     additions: int,
     *     deletions: int,
     *     labels: list<string>,
     *     state_reason?: ?string,
     *     counted_same_day: int,
     * }  $candidate
     * @return ?string  null when it counts, otherwise the reason it does not
     */
    public function refusal(array $candidate): ?string
    {
        $login = $candidate['login'];

        if ($candidate['account_type'] === 'Bot' || str_ends_with(strtolower($login), '[bot]')) {
            return self::BOT;
        }

        $excluded = array_map('strtolower', (array) ($this->rules['excluded_logins'] ?? []));

        if (in_array(strtolower($login), $excluded, true)) {
            return self::EXCLUDED;
        }

        if ($candidate['banned']) {
            return self::BANNED;
        }

        $minimumAge = (int) ($this->rules['min_account_age_days'] ?? 0);
        $createdAt = $candidate['account_created_at'];

        // An account whose age could not be read is treated as new: the rule
        // fails closed, and the next sync re-reads it.
        if ($minimumAge > 0 && ($createdAt === null || $createdAt->diffInDays($candidate['accepted_at'], false) < $minimumAge)) {
            return self::ACCOUNT_TOO_NEW;
        }

        if ($candidate['kind'] === Contribution::ISSUE) {
            $accepted = array_map('strtolower', $this->issueLabels);
            $labels = array_map('strtolower', $candidate['labels']);

            if (($candidate['state_reason'] ?? null) !== 'completed' || array_intersect($accepted, $labels) === []) {
                return self::NOT_ACCEPTED;
            }

            return null;
        }

        if (($this->rules['refuse_self_merged'] ?? true)
            && $candidate['merged_by'] !== null
            && strcasecmp($candidate['merged_by'], $login) === 0) {
            return self::SELF_MERGED;
        }

        if ($this->scorer->isTrivial($candidate['kind'], $candidate['additions'], $candidate['deletions'])) {
            return self::TRIVIAL;
        }

        $perDay = (int) ($this->rules['max_per_day'] ?? 0);

        if ($perDay > 0 && $candidate['counted_same_day'] >= $perDay) {
            return self::DAILY_CAP;
        }

        return null;
    }
}
