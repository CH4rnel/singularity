<?php

namespace App\Console\Commands;

use App\Services\BridgeRelayerService;
use App\Support\Environment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Satellite-farm funding keeper (Path A of the unified ASH emission design).
 *
 * Shells out to crypto/hardhat/scripts/fund-satellite-farms.ts, which for each
 * satellite chain harvests its EmissionChannel share on the Cyberia chef,
 * mints backed bridged ASH, tops up the chain's FundedFarm and keeps its
 * rewardPerBlock synced. ASH is only ever minted on Cyberia, so bridged supply
 * stays backed 1:1 and total emission is capped by the Cyberia chef alone.
 */
#[Signature('farm:fund-satellites {--buffer-days=7 : Days of rewards to keep each FundedFarm topped up to}')]
#[Description('Harvest satellite emission channels and fund their FundedFarms with bridged ASH')]
class FarmFundSatellitesCommand extends Command
{
    public function handle(BridgeRelayerService $relayer): int
    {
        $key = $relayer->privateKey();

        if (! $key) {
            $this->error('Relayer private key unavailable — cannot fund satellites.');

            return self::FAILURE;
        }

        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        $result = Process::path($hardhatDir)
            ->env([
                'BRIDGE_RELAYER_PRIVATE_KEY' => $key,
                'BUFFER_DAYS' => (string) $this->option('buffer-days'),
                'CYBERIA_RPC_URL' => (string) config('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church'),
                'ROBINHOOD_RPC_URL' => (string) config('bridge.chains.robinhood.rpc_url', 'https://rpc.mainnet.chain.robinhood.com'),
            ])
            ->timeout(180)
            ->run(['npx', 'tsx', 'scripts/fund-satellite-farms.ts']);

        $output = trim($result->output().$result->errorOutput());

        if (! $result->successful()) {
            Log::error('farm:fund-satellites failed', ['exit' => $result->exitCode(), 'output' => $output]);
            $this->error('Keeper failed:');
            $this->line($output);

            return self::FAILURE;
        }

        Log::info('farm:fund-satellites ok', ['output' => $output]);
        $this->line($output);

        return self::SUCCESS;
    }
}
