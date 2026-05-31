<?php

namespace App\Console\Commands;

use App\Services\BridgeRelayerService;
use App\Support\Environment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bridge:show-relayer')]
#[Description('Show the bridge relayer EVM address (derived from the configured private key).')]
class BridgeShowRelayerCommand extends Command
{
    private function describe(mixed $v): string
    {
        if ($v === null) {
            return 'unset';
        }
        if ($v === '') {
            return 'empty';
        }

        return 'set ('.strlen((string) $v).' chars)';
    }

    public function handle(BridgeRelayerService $relayer): int
    {
        $pk = $relayer->privateKey();

        if ($pk === null) {
            $hardhatEnv = Environment::isProduction()
                ? '/singularity/crypto/hardhat/.env'
                : base_path('/../../crypto/hardhat/.env');

            $this->error('No relayer private key configured.');
            $this->newLine();
            $this->line('Diagnostics (we never print the key itself):');
            $this->line('  Laravel .env BRIDGE_RELAYER_PRIVATE_KEY: '.$this->describe(env('BRIDGE_RELAYER_PRIVATE_KEY')));
            $this->line('  Laravel .env DEPLOYER_PK:                '.$this->describe(env('DEPLOYER_PK')));
            $this->line(sprintf(
                '  %s: %s',
                $hardhatEnv,
                is_file($hardhatEnv) ? 'exists' : 'not found',
            ));
            $this->newLine();
            $this->line('Put <fg=cyan>DEPLOYER_PK=0x...</> in <fg=cyan>'.$hardhatEnv.'</>');
            $this->line('(Laravel reads it from there — no need to duplicate in backend/laravel/.env.)');

            return self::FAILURE;
        }

        $address = $relayer->evmAddress();

        if ($address === null) {
            $this->error('Failed to derive relayer address from private key.');

            return self::FAILURE;
        }

        $configured = (string) config('services.bridge.relayer_address');

        $this->info("Relayer EVM address: {$address}");
        $this->newLine();

        if ($configured === '') {
            $this->line('Derived from DEPLOYER_PK / BRIDGE_RELAYER_PRIVATE_KEY automatically.');
            $this->line('No BRIDGE_RELAYER_ADDRESS override needed.');

            return self::SUCCESS;
        }

        if (strtolower($configured) !== strtolower($address)) {
            $this->error("Mismatch: .env BRIDGE_RELAYER_ADDRESS={$configured}");
            $this->error("but private key derives to            {$address}");
            $this->newLine();
            $this->line('Fix or remove BRIDGE_RELAYER_ADDRESS in .env.');

            return self::FAILURE;
        }

        $this->info('BRIDGE_RELAYER_ADDRESS in .env matches the derived address.');

        return self::SUCCESS;
    }
}
