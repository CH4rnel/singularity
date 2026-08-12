<?php

namespace App\Console\Commands;

use App\Services\BridgeRelayerService;
use App\Services\Predictions\PredictionMarketReader;
use App\Services\Predictions\PredictionQuestion;
use App\Services\Predictions\PredictionsResolver;
use App\Services\TelegramOpsNotifier;
use App\Services\WalletPriceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The oracle showing up.
 *
 * Reads every market, settles the ones whose question answers itself from a
 * price feed, cancels the ones nobody could answer before the contract's
 * refund window makes them unresolvable, and reports the rest to a human who
 * can. Runs on a schedule; also the manual lever, `--id` with `--outcome`.
 */
#[Signature('predictions:resolve
    {--dry-run : Print the plan without signing anything}
    {--id= : Settle one market by hand instead of running the plan}
    {--outcome= : yes|no|invalid, together with --id}
    {--no-alert : Do not send the Telegram report}')]
#[Description('Resolve closed prediction markets and report the ones needing a human')]
class PredictionsResolveCommand extends Command
{
    private const OUTCOME_ARG = [
        'yes' => PredictionsResolver::OUTCOME_YES,
        'no' => PredictionsResolver::OUTCOME_NO,
        'invalid' => PredictionsResolver::OUTCOME_INVALID,
    ];

    public function handle(
        PredictionMarketReader $reader,
        PredictionsResolver $resolver,
        WalletPriceService $prices,
        BridgeRelayerService $relayer,
        TelegramOpsNotifier $telegram,
    ): int {
        if (! $reader->enabled()) {
            $this->error('predictions.contract_address is not set.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->oracleMatches($reader, $relayer)) {
            return self::FAILURE;
        }

        if ($this->option('id') !== null) {
            return $this->resolveByHand($resolver, $dryRun);
        }

        $markets = $reader->markets();

        if ($markets === null) {
            $this->error('Could not read markets from the chain.');

            return self::FAILURE;
        }

        $grace = config('predictions.auto_cancel')
            ? (int) config('predictions.cancel_grace_days', 3) * 86400
            // Negative grace never triggers: the plan then only reports.
            : -1;

        $plan = $resolver->plan(
            $markets,
            $prices->quotes()['prices'] ?? [],
            time(),
            $reader->resolveWindow(),
            $grace,
        );

        $decisions = array_slice($plan['resolve'], 0, (int) config('predictions.max_per_run', 20));

        $this->report($plan, $decisions);

        if ($dryRun) {
            $this->line('');
            $this->info('Dry run — nothing was signed.');

            return self::SUCCESS;
        }

        $results = $resolver->submit($decisions);

        foreach ($results as $result) {
            $this->line(sprintf(
                '  #%d → %s',
                $result['id'],
                $result['ok']
                    ? 'sent '.($result['txHash'] ?? '')
                    : 'FAILED '.($result['error'] ?? 'unknown'),
            ));
        }

        if ($results !== []) {
            Log::info('predictions:resolve settled markets', ['results' => $results]);
        }

        if (! $this->option('no-alert') && config('predictions.alerts.enabled')) {
            $this->notifyOperator($telegram, $plan, $results);
        }

        return self::SUCCESS;
    }

    /**
     * Resolution is onlyOwner, so a signer that is not the owner produces a
     * revert per market and an empty run. Say it once, up front, instead.
     */
    private function oracleMatches(PredictionMarketReader $reader, BridgeRelayerService $relayer): bool
    {
        $oracle = $reader->oracle();
        $signer = $relayer->evmAddress();

        if ($oracle === null) {
            $this->error('Could not read the contract owner — is the RPC up?');

            return false;
        }

        if ($signer === null) {
            $this->error('Relayer key unavailable — nothing can be signed.');

            return false;
        }

        if (strtolower($oracle) !== strtolower($signer)) {
            $this->error("The signer is not the oracle: contract owner is {$oracle}, this host signs as {$signer}.");

            return false;
        }

        return true;
    }

    private function resolveByHand(PredictionsResolver $resolver, bool $dryRun): int
    {
        $id = (int) $this->option('id');
        $outcome = self::OUTCOME_ARG[strtolower((string) $this->option('outcome'))] ?? null;

        if ($outcome === null) {
            $this->error('--outcome must be yes, no or invalid.');

            return self::FAILURE;
        }

        $this->line("Market #{$id} → ".strtolower((string) $this->option('outcome')));

        if ($dryRun) {
            $this->info('Dry run — nothing was signed.');

            return self::SUCCESS;
        }

        $results = $resolver->submit([['id' => $id, 'outcome' => $outcome]]);
        $result = $results[0] ?? null;

        if ($result === null || ! $result['ok']) {
            $this->error('Failed: '.($result['error'] ?? 'no result reported'));

            return self::FAILURE;
        }

        $this->info('Sent '.($result['txHash'] ?? ''));

        return self::SUCCESS;
    }

    /**
     * @param  array{resolve: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, expired: array<int, array<string, mixed>>}  $plan
     * @param  array<int, array<string, mixed>>  $decisions
     */
    private function report(array $plan, array $decisions): void
    {
        $this->line(sprintf(
            'to settle: %d (this run: %d) · waiting on a human: %d · past the refund window: %d',
            count($plan['resolve']),
            count($decisions),
            count($plan['pending']),
            count($plan['expired']),
        ));

        foreach ($decisions as $decision) {
            $this->line(sprintf(
                '  #%d %s — %s (pot %s CYBER)',
                $decision['id'],
                $this->outcomeName((int) $decision['outcome']),
                $decision['reason'],
                self::cyber((string) $decision['pot']),
            ));
        }

        foreach ($plan['pending'] as $pending) {
            $this->line(sprintf(
                '  #%d pending — %s, %s left (pot %s CYBER)',
                $pending['id'],
                $pending['reason'],
                self::humanDuration((int) $pending['deadline'] - time()),
                self::cyber((string) $pending['pot']),
            ));
        }

        foreach ($plan['expired'] as $expired) {
            $this->line(sprintf(
                '  #%d unresolvable — refunds only (pot %s CYBER)',
                $expired['id'],
                self::cyber((string) $expired['pot']),
            ));
        }
    }

    /**
     * Tell the operator what happened, and keep telling them about markets
     * only they can settle — once a day, not once a run.
     *
     * @param  array{resolve: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, expired: array<int, array<string, mixed>>}  $plan
     * @param  array<int, array<string, mixed>>  $results
     */
    private function notifyOperator(TelegramOpsNotifier $telegram, array $plan, array $results): void
    {
        $lines = [];

        foreach ($results as $result) {
            $lines[] = $result['ok']
                ? sprintf('✅ #%d settled (%s)', $result['id'], $this->outcomeName((int) $result['outcome']))
                : sprintf('⚠️ #%d failed: %s', $result['id'], $result['error'] ?? 'unknown');
        }

        $pendingLines = [];

        foreach ($plan['pending'] as $pending) {
            $pendingLines[] = sprintf(
                '❓ #%d «%s» — %s. %s until refunds.',
                $pending['id'],
                PredictionQuestion::prose((string) $pending['question']),
                $pending['reason'],
                self::humanDuration((int) $pending['deadline'] - time()),
            );
        }

        // A market waiting on a person is worth repeating, but not every five
        // minutes. The fingerprint carries days-left, so the reminder returns
        // on its own as the deadline gets closer.
        if ($pendingLines !== []) {
            $fingerprint = sha1(implode('|', array_map(
                fn (array $p): string => $p['id'].':'.intdiv(max(0, (int) $p['deadline'] - time()), 86400),
                $plan['pending'],
            )));

            if (Cache::get('predictions.alert.pending') !== $fingerprint) {
                Cache::put(
                    'predictions.alert.pending',
                    $fingerprint,
                    now()->addHours((int) config('predictions.alerts.repeat_hours', 24)),
                );
                $lines = [...$lines, ...$pendingLines];
            }
        }

        if ($lines === []) {
            return;
        }

        $telegram->send("<b>Predictions</b>\n".implode("\n", $lines));
    }

    private function outcomeName(int $outcome): string
    {
        return match ($outcome) {
            PredictionsResolver::OUTCOME_YES => 'YES',
            PredictionsResolver::OUTCOME_NO => 'NO',
            PredictionsResolver::OUTCOME_INVALID => 'CANCELLED',
            default => 'none',
        };
    }

    /** wei string → a short CYBER amount for a log line. */
    private static function cyber(string $wei): string
    {
        return rtrim(rtrim(bcdiv($wei, '1000000000000000000', 4), '0'), '.') ?: '0';
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'no time';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600).'h';
        }

        return intdiv($seconds, 86400).'d';
    }
}
