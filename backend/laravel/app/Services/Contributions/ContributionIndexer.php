<?php

namespace App\Services\Contributions;

use App\Models\Contribution;
use App\Models\ContributionRepository;
use App\Models\ContributorAccount;
use Illuminate\Support\Carbon;

/**
 * Reads merged pull requests and accepted issues out of verified repositories
 * and writes each one down with its points and its verdict.
 *
 * Idempotent by (repository, kind, number): a re-sync re-judges what is not
 * yet frozen and inserts nothing twice. Two things are frozen — a row an
 * operator reviewed by hand, and a row an epoch already paid — because the
 * sync re-reading GitHub must never undo a person's decision or move money
 * that a published root already promised.
 */
class ContributionIndexer
{
    public function __construct(
        private readonly GithubClient $github,
        private readonly SybilGuard $guard,
        private readonly ContributionScorer $scorer,
    ) {}

    /**
     * @return array{pulls: int, issues: int, counted: int, rejected: int, complete: bool}
     */
    public function sync(ContributionRepository $repository): array
    {
        $since = $repository->synced_at
            ? $repository->synced_at->copy()->subDay()
            : now()->subDays((int) config('contributions.github.backfill_days', 90));

        $stats = ['pulls' => 0, 'issues' => 0, 'counted' => 0, 'rejected' => 0, 'complete' => true];
        $candidates = [];

        for ($page = 1; $page <= (int) config('contributions.github.page_limit', 3); $page++) {
            $pulls = $this->github->closedPullRequests($repository->full_name, $page);

            if ($pulls === null) {
                $stats['complete'] = false;
                break;
            }

            $older = false;

            foreach ($pulls as $pull) {
                if (Carbon::parse((string) $pull['updated_at'])->lt($since)) {
                    $older = true;

                    continue;
                }

                if (! empty($pull['merged_at'])) {
                    $candidates[] = ['kind' => 'pull', 'at' => (string) $pull['merged_at'], 'item' => $pull];
                }
            }

            if ($older || count($pulls) < 100) {
                break;
            }
        }

        $issues = $this->github->closedIssues($repository->full_name, $since->toIso8601ZuluString());

        if ($issues === null) {
            $stats['complete'] = false;
        }

        foreach ($issues ?? [] as $issue) {
            if (! isset($issue['pull_request']) && ! empty($issue['closed_at'])) {
                $candidates[] = ['kind' => 'issue', 'at' => (string) $issue['closed_at'], 'item' => $issue];
            }
        }

        // Oldest first, so the daily cap counts the first N of a day and not
        // whichever GitHub happened to list first.
        usort($candidates, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        foreach ($candidates as $candidate) {
            $row = $candidate['kind'] === 'pull'
                ? $this->recordPull($repository, $candidate['item'])
                : $this->recordIssue($repository, $candidate['item']);

            if ($row === null) {
                if ($candidate['kind'] === 'pull') {
                    $stats['complete'] = false;
                }

                continue;
            }

            $stats[$candidate['kind'] === 'pull' ? 'pulls' : 'issues']++;
            $stats[$row->status === Contribution::COUNTED ? 'counted' : 'rejected']++;
        }

        // Only a run that read everything moves the window forward; a partial
        // one is retried from the same place next time.
        if ($stats['complete']) {
            $repository->forceFill(['synced_at' => now()])->save();
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $pull
     */
    private function recordPull(ContributionRepository $repository, array $pull): ?Contribution
    {
        $number = (int) $pull['number'];
        $labels = $this->labels($pull);
        $kind = $this->scorer->kindOfPullRequest((string) $pull['title'], $labels);

        $existing = $this->existing($repository, [Contribution::PULL_REQUEST, Contribution::DOCUMENTATION], $number);

        if ($existing !== null && $this->frozen($existing)) {
            return $existing;
        }

        $full = $this->github->pullRequest($repository->full_name, $number);

        if ($full === null) {
            return null;
        }

        return $this->judge($repository, $existing, [
            'kind' => $kind,
            'number' => $number,
            'title' => (string) $pull['title'],
            'url' => (string) $pull['html_url'],
            'github_id' => (string) ($pull['user']['id'] ?? ''),
            'login' => (string) ($pull['user']['login'] ?? ''),
            'account_type' => (string) ($pull['user']['type'] ?? 'User'),
            'additions' => (int) ($full['additions'] ?? 0),
            'deletions' => (int) ($full['deletions'] ?? 0),
            'commits' => (int) ($full['commits'] ?? 0),
            'merged_by' => isset($full['merged_by']['login']) ? (string) $full['merged_by']['login'] : null,
            'accepted_at' => Carbon::parse((string) $pull['merged_at']),
            'labels' => $labels,
            'state_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function recordIssue(ContributionRepository $repository, array $issue): ?Contribution
    {
        $number = (int) $issue['number'];
        $existing = $this->existing($repository, [Contribution::ISSUE], $number);

        if ($existing !== null && $this->frozen($existing)) {
            return $existing;
        }

        return $this->judge($repository, $existing, [
            'kind' => Contribution::ISSUE,
            'number' => $number,
            'title' => (string) $issue['title'],
            'url' => (string) $issue['html_url'],
            'github_id' => (string) ($issue['user']['id'] ?? ''),
            'login' => (string) ($issue['user']['login'] ?? ''),
            'account_type' => (string) ($issue['user']['type'] ?? 'User'),
            'additions' => 0,
            'deletions' => 0,
            'commits' => 0,
            'merged_by' => null,
            'accepted_at' => Carbon::parse((string) $issue['closed_at']),
            'labels' => $this->labels($issue),
            'state_reason' => isset($issue['state_reason']) ? (string) $issue['state_reason'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function judge(ContributionRepository $repository, ?Contribution $existing, array $facts): ?Contribution
    {
        if ($facts['github_id'] === '' || $facts['login'] === '') {
            return null;
        }

        $account = $this->account($facts['github_id'], $facts['login'], $facts['account_type']);

        /** @var Carbon $acceptedAt */
        $acceptedAt = $facts['accepted_at'];

        $countedSameDay = Contribution::query()
            ->where('contribution_repository_id', $repository->id)
            ->where('github_id', $facts['github_id'])
            ->where('status', Contribution::COUNTED)
            ->where('kind', '!=', Contribution::ISSUE)
            ->whereBetween('accepted_at', [$acceptedAt->copy()->startOfDay(), $acceptedAt->copy()->endOfDay()])
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
            ->where('accepted_at', '<', $acceptedAt)
            ->count();

        $reason = $this->guard->refusal([
            'kind' => $facts['kind'],
            'login' => $facts['login'],
            'account_type' => $account->type,
            'account_created_at' => $account->github_created_at,
            'banned' => $account->banned_at !== null,
            'accepted_at' => $acceptedAt,
            'merged_by' => $facts['merged_by'],
            'additions' => $facts['additions'],
            'deletions' => $facts['deletions'],
            'labels' => $facts['labels'],
            'state_reason' => $facts['state_reason'],
            'counted_same_day' => $countedSameDay,
        ]);

        $points = $reason === null
            ? $this->scorer->score($facts['kind'], $facts['additions'], $facts['deletions'], (float) $repository->weight)
            : 0;

        $row = $existing ?? new Contribution(['contribution_repository_id' => $repository->id]);

        $row->fill([
            'github_id' => $facts['github_id'],
            'login' => $facts['login'],
            'kind' => $facts['kind'],
            'number' => $facts['number'],
            'title' => mb_substr($facts['title'], 0, 250),
            'url' => $facts['url'],
            'additions' => $facts['additions'],
            'deletions' => $facts['deletions'],
            'commits' => $facts['commits'],
            'merged_by' => $facts['merged_by'],
            'accepted_at' => $acceptedAt,
            'points' => $points,
            'status' => $reason === null ? Contribution::COUNTED : Contribution::REJECTED,
            'reason' => $reason,
        ])->save();

        return $row;
    }

    private function account(string $githubId, string $login, string $type): ContributorAccount
    {
        $account = ContributorAccount::query()->firstOrNew(['github_id' => $githubId], [
            'login' => $login,
            'type' => $type,
        ]);

        if ($account->fetched_at === null || $account->fetched_at->lt(now()->subDays(7)) || $account->github_created_at === null) {
            $profile = $this->github->account($githubId);

            if ($profile !== null) {
                $account->fill([
                    'login' => (string) ($profile['login'] ?? $login),
                    'type' => (string) ($profile['type'] ?? $type),
                    'github_created_at' => isset($profile['created_at']) ? Carbon::parse((string) $profile['created_at']) : null,
                    'followers' => isset($profile['followers']) ? (int) $profile['followers'] : null,
                    'public_repos' => isset($profile['public_repos']) ? (int) $profile['public_repos'] : null,
                    'fetched_at' => now(),
                ]);
            }
        }

        $account->save();

        return $account;
    }

    /**
     * @param  list<string>  $kinds
     */
    private function existing(ContributionRepository $repository, array $kinds, int $number): ?Contribution
    {
        return Contribution::query()
            ->where('contribution_repository_id', $repository->id)
            ->whereIn('kind', $kinds)
            ->where('number', $number)
            ->first();
    }

    private function frozen(Contribution $row): bool
    {
        return $row->reviewed_at !== null || $row->contribution_epoch_id !== null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function labels(array $item): array
    {
        return array_values(array_filter(array_map(
            fn ($label): string => is_array($label) ? (string) ($label['name'] ?? '') : '',
            (array) ($item['labels'] ?? []),
        )));
    }
}
