<?php

namespace App\Services\Contributions;

use App\Models\ContributionRepository;

/**
 * A repository earns rewards only after it proves who controls it.
 *
 * The proof is a file on its default branch — `.cyberia/rewards.json` with
 * `{"chainId": 49406}` — because only somebody with write access can put it
 * there, and removing it is how a maintainer opts out. Listing a repository in
 * config is a wish; this is what makes it pay. A failed read leaves the last
 * verdict standing rather than revoking a repository over a GitHub outage.
 */
class RepositoryVerifier
{
    public const MISSING = 'missing';

    public const MALFORMED = 'malformed';

    public const WRONG_CHAIN = 'wrongChain';

    public function __construct(private readonly GithubClient $github) {}

    public function verify(ContributionRepository $repository): ContributionRepository
    {
        $content = $this->github->file($repository->full_name, (string) config('contributions.verification_file'));

        $error = $this->problem($content);

        $repository->forceFill([
            'verified_at' => $error === null ? ($repository->verified_at ?? now()) : null,
            'verification_error' => $error,
        ])->save();

        return $repository;
    }

    private function problem(?string $content): ?string
    {
        if ($content === null) {
            return self::MISSING;
        }

        $json = json_decode($content, true);

        if (! is_array($json)) {
            return self::MALFORMED;
        }

        return (int) ($json['chainId'] ?? 0) === (int) config('contributions.contract.chain_id')
            ? null
            : self::WRONG_CHAIN;
    }
}
