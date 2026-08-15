<?php

namespace App\Console\Commands;

use App\Models\GasSponsorship;
use App\Services\GasSponsorService;
use App\Services\TelegramOpsNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Where the gas station stands, and a shout when it is about to stop working.
 *
 * There are two ways for sponsored fees to quietly die, and the difference is
 * the whole reason this command exists: the tank runs out of CYBER to give
 * away, or the operator key runs out of CYBER to pay for giving it away. Both
 * look identical from the outside — the button stops working — and the second
 * one has already happened once on this host, to the bridge relayer, unnoticed
 * for hours.
 *
 * Reads only. Funding, policy and pausing are `scripts/gas-station.ts`, which
 * takes a key; this takes none.
 */
#[Signature('gas:station {--alert : Report to the operator when the tank or the key is low}')]
#[Description('Show the wallet gas station: tank, operator key, and what it has served')]
class GasStationCommand extends Command
{
    /** Hours between repeats of the same warning, so it stays readable. */
    private const ALERT_SILENCE_HOURS = 6;

    public function handle(GasSponsorService $sponsor, TelegramOpsNotifier $telegram): int
    {
        if (! $sponsor->enabled()) {
            $this->warn('Sponsored fees are off.');
            $this->line(match (true) {
                ! (bool) config('wallet.sponsor.enabled') => '  WALLET_GAS_SPONSOR_ENABLED is false.',
                $sponsor->station() === null => '  WALLET_GAS_STATION_ADDRESS is unset — deploy scripts/deploy-gas-station.ts first.',
                default => '  GAS_SPONSOR_PRIVATE_KEY is unset. There is deliberately no fallback to the bridge relayer key.',
            });

            return self::SUCCESS;
        }

        $summary = $sponsor->summary();

        if ($summary === null) {
            $this->error('The station could not be read. This is an RPC problem, not an empty tank.');

            return self::FAILURE;
        }

        $operator = $sponsor->operatorAddress();
        $operatorBalance = $operator === null ? null : $sponsor->nativeBalance($operator);
        $drip = $summary['drip'] === '0' ? '1' : $summary['drip'];
        $dripsLeft = (int) bcdiv($summary['tank'], $drip, 0);
        $servedToday = GasSponsorship::where('created_at', '>=', now()->startOfDay())->count();

        $this->line('Station:  '.$sponsor->station().($summary['paused'] ? '  [PAUSED]' : ''));
        $this->line('Tank:     '.$this->cyber($summary['tank']).' CYBER  ('.$dripsLeft.' drips left)');
        $this->line('Drip:     '.$this->cyber($summary['drip']).' CYBER per claim, '
            .'ceiling '.$this->cyber($summary['ceiling']).', cooldown '.round($summary['cooldown'] / 3600, 1).'h');
        $this->line('Today:    '.$this->cyber($summary['remainingToday']).' CYBER left of '
            .$this->cyber($summary['dailyCap']).' cap, '.$servedToday.' served');
        $this->line('Lifetime: '.$summary['served'].' drips, '.$this->cyber($summary['spent']).' CYBER');
        $this->line('Operator: '.($operator ?? 'unreadable key').'  '
            .($operatorBalance === null ? '(balance unreadable)' : $this->cyber($operatorBalance).' CYBER'));

        $warnings = $this->warnings($summary, $dripsLeft, $operatorBalance);

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if ($this->option('alert') && $warnings !== []) {
            $this->notifyOperator($telegram, $warnings, $summary, $dripsLeft);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{tank: string, drip: string, ceiling: string, cooldown: int, dailyCap: string, remainingToday: string, served: int, spent: string, paused: bool}  $summary
     * @return array<int, string>
     */
    private function warnings(array $summary, int $dripsLeft, ?string $operatorBalance): array
    {
        $warnings = [];

        if ($summary['paused']) {
            $warnings[] = 'The station is paused: nobody is being sponsored.';
        }

        if ($dripsLeft < (int) config('wallet.sponsor.low_water_drips', 50)) {
            $warnings[] = 'Tank low: '.$dripsLeft.' drips left ('.$this->cyber($summary['tank']).' CYBER).';
        }

        $minimum = (string) config('wallet.sponsor.operator_min_wei', '0');

        if ($operatorBalance !== null && bccomp($operatorBalance, $minimum) < 0) {
            $warnings[] = 'Operator key low: '.$this->cyber($operatorBalance)
                .' CYBER. It pays the gas that delivers each drip, so a full tank behind it sponsors nobody.';
        }

        return $warnings;
    }

    /**
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $summary
     */
    private function notifyOperator(
        TelegramOpsNotifier $telegram,
        array $warnings,
        array $summary,
        int $dripsLeft,
    ): void {
        // One shout per problem per silence window: a scheduled command that
        // repeats itself every fifteen minutes is a command people mute.
        $key = 'wallet.gas-station:alerted:'.md5(implode('|', $warnings));

        if (Cache::has($key)) {
            return;
        }

        $sent = $telegram->send(
            "⛽ <b>Cyberia gas station</b>\n\n"
            .implode("\n", array_map(fn (string $line) => '• '.e($line), $warnings))
            ."\n\nTank: ".$this->cyber((string) $summary['tank']).' CYBER ('.$dripsLeft.' drips)'
            ."\nRefill: <code>npx tsx scripts/gas-station.ts fund &lt;station&gt; &lt;cyber&gt;</code>",
        );

        if ($sent) {
            Cache::put($key, true, now()->addHours(self::ALERT_SILENCE_HOURS));
        }
    }

    /** Wei to CYBER, as a string — the amounts here overflow PHP's integers. */
    private function cyber(string $wei): string
    {
        return rtrim(rtrim(bcdiv($wei, '1000000000000000000', 6), '0'), '.') ?: '0';
    }
}
