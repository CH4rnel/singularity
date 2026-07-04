<?php

namespace App\Console\Commands;

use App\Models\BridgeRequest;
use App\Services\Yenten\YentenAddressDeriver;
use App\Support\Environment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('bridge:yenten-sweep
    {id? : Sweep a single completed request by id (omit to sweep all unswept)}
    {--to= : Destination Yenten address (defaults to the central wallet)}
    {--force : Re-run even if the request is already marked swept}')]
#[Description('Move Yenten deposits from per-request one-time addresses to another wallet (central wallet by default).')]
class BridgeYentenSweepCommand extends Command
{
    public function handle(): int
    {
        $destination = (string) ($this->option('to')
            ?: config('bridge.chains.yenten.deposit_address'));

        if ($destination === '') {
            $this->error('No destination: pass --to=<address> or set BRIDGE_YENTEN_DEPOSIT_ADDRESS.');

            return self::FAILURE;
        }

        $query = BridgeRequest::query()
            ->where('source_chain', 'yenten')
            ->where('status', 'completed')
            ->whereNotNull('deposit_address');

        if ($id = $this->argument('id')) {
            $query->whereKey($id);
        } elseif (! $this->option('force')) {
            $query->where('swept', false);
        }

        $requests = $query->get();

        if ($requests->isEmpty()) {
            $this->info('Nothing to sweep.');

            return self::SUCCESS;
        }

        $scriptDir = Environment::isProduction()
            ? '/singularity/crypto/yenten'
            : base_path('/../../crypto/yenten');

        $swept = 0;

        $deriver = YentenAddressDeriver::fromConfig();

        foreach ($requests as $request) {
            // Prefer the stored key; fall back to deriving from the seed.
            $wif = $request->deposit_wif ?: $deriver->childWif($request->id);

            $result = Process::path($scriptDir)
                ->env([
                    'YENTEN_CHILD_WIF' => $wif,
                    'YENTEN_API_URL' => (string) config('bridge.chains.yenten.api_url', 'https://api.yentencoin.info'),
                ])
                ->timeout(180)
                ->run(['npm', 'run', '--silent', 'sweep', '--', $destination]);

            if ($result->exitCode() !== 0) {
                $this->warn("Request #{$request->id}: sweep failed — ".trim($result->errorOutput()));

                continue;
            }

            $request->update(['swept' => true]);
            $swept++;
            $this->line("Request #{$request->id}: swept.");
        }

        $this->info("Swept {$swept} of {$requests->count()} request(s).");

        return self::SUCCESS;
    }
}
