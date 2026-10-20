<?php

/**
 * The open-source reward engine (whitepaper phase X).
 *
 * GitHub shows the work, Cyberia settles the reward. The pipeline is one
 * direction and every stage can be read on its own:
 *
 *   contributions:sync   — merged pull requests and accepted issues in the
 *                          verified repositories, read from GitHub
 *   SybilGuard           — what counts, what is held for a wallet and what is
 *                          refused, each with its reason written on the row
 *   contributions:epoch  — one period's points split over one pool, written
 *                          as a Merkle tree whose root is published on chain
 *   CyberiaContributorRewards.sol — anyone claims their leaf with its proof
 *
 * Every value here that spends money is a decision for the project, not for
 * the code, and the defaults are chosen so that nothing is paid until somebody
 * makes it: an unset pool allocates nothing.
 */
return [

    /*
    | A token used only to read GitHub (public data, so no scope is needed).
    | Unset works too, at GitHub's anonymous 60 requests an hour.
    */
    'github' => [
        'token' => env('CONTRIBUTIONS_GITHUB_TOKEN'),
        'api' => env('CONTRIBUTIONS_GITHUB_API', 'https://api.github.com'),
        'timeout' => 15,
        // How far back the first sync of a repository reads.
        'backfill_days' => (int) env('CONTRIBUTIONS_BACKFILL_DAYS', 90),
        // Pull requests read per repository per sync (newest first).
        'page_limit' => 3,
    ],

    /*
    | Repositories that earn rewards. A repository is listed here or added with
    | `contributions:repo add`, and it pays only once it is *verified*: its
    | default branch carries `verification_file` naming this chain, so a repo
    | is never enrolled by somebody who does not control it.
    */
    'repositories' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CONTRIBUTIONS_REPOSITORIES',
        'cyberia-temple/singularity,lain-labs/os,lain-labs/backend',
    ))))),
    'verification_file' => '.cyberia/rewards.json',

    /*
    | Points. A merged pull request is worth `base` scaled by its size on a
    | logarithmic curve (ten times the lines is not ten times the work, and is
    | very often a lockfile), capped at `max_size_multiplier`. Anything under
    | `min_changed_lines` is trivial and scores nothing — typo farming is the
    | cheapest attack on a scheme like this one.
    */
    'points' => [
        'pull_request' => 10,
        'documentation' => 6,
        'issue' => 3,
        'min_changed_lines' => (int) env('CONTRIBUTIONS_MIN_CHANGED_LINES', 5),
        'size_unit_lines' => 100,
        'max_size_multiplier' => 3.0,
        // A pull request is documentation when it carries one of these labels
        // or its title starts with `docs`.
        'documentation_labels' => ['documentation', 'docs'],
        // An issue pays only when a maintainer accepted it with one of these
        // labels and closed it as completed. An unlabelled issue is a request,
        // not a contribution.
        'issue_labels' => ['bug', 'accepted', 'confirmed'],
    ],

    /*
    | Anti-spam and anti-sybil. A contribution has to clear every rule here to
    | count; a refusal keeps its reason on the row and is visible on the
    | contributor's own page.
    */
    'sybil' => [
        // A GitHub account younger than this when it contributed scores
        // nothing. A sybil farm can make accounts for free; it cannot make
        // them old.
        'min_account_age_days' => (int) env('CONTRIBUTIONS_MIN_ACCOUNT_AGE_DAYS', 90),
        // A pull request merged by its own author was reviewed by nobody.
        'refuse_self_merged' => true,
        // Counted pull requests per author per repository per UTC day.
        'max_per_day' => (int) env('CONTRIBUTIONS_MAX_PER_DAY', 3),
        // Logins that never earn: the core team if it is paid otherwise.
        'excluded_logins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CONTRIBUTIONS_EXCLUDED_LOGINS', ''))))),
    ],

    /*
    | The pool. Paid per epoch, in one asset, by CyberiaContributorRewards.
    |
    | DECISION FOR THE PROJECT: what the reward is paid in and how much of it
    | an epoch distributes. `amount` = 0 means no epoch allocates anything;
    | the build says so instead of writing a tree of zeroes.
    */
    'pool' => [
        // `native` for CYBER on Cyberia, or an ERC-20 address on chain 49406.
        'asset' => env('CONTRIBUTIONS_POOL_ASSET', 'native'),
        'symbol' => env('CONTRIBUTIONS_POOL_SYMBOL', 'CYBER'),
        'decimals' => (int) env('CONTRIBUTIONS_POOL_DECIMALS', 18),
        // Human units per epoch, e.g. "100000".
        'amount' => (string) env('CONTRIBUTIONS_POOL_AMOUNT', '0'),
        // No contributor takes more than this share of one epoch, in basis
        // points; what the cap frees is shared among everybody under it.
        'max_share_bps' => (int) env('CONTRIBUTIONS_MAX_SHARE_BPS', 2500),
        // Below this many points an epoch pays nothing to that contributor,
        // so dust never becomes a transaction somebody has to pay gas for.
        'min_points' => (float) env('CONTRIBUTIONS_MIN_POINTS', 5),
    ],

    'epochs' => [
        // The first epoch opens here; each next one opens where the last closed.
        'first_starts_at' => env('CONTRIBUTIONS_FIRST_EPOCH_AT', '2026-11-01 00:00:00'),
        'length_days' => (int) env('CONTRIBUTIONS_EPOCH_DAYS', 30),
    ],

    /*
    | Where rewards are claimed. Unset = the board and the points work, the
    | claim button explains that nothing is published yet.
    */
    'contract' => [
        'address' => env('CONTRIBUTIONS_CONTRACT_ADDRESS'),
        'chain_id' => 49406,
    ],
];
