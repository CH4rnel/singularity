<?php

namespace App\Console\Commands;

use App\Services\BridgeRelayerService;
use App\Support\Environment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('bridge:gas-drop
    {address : EVM recipient address}
    {--amount= : CYBER amount (defaults to bridge.gas_drop.amount_cyber)}')]
#[Description('Send a one-off native CYBER gas drop from the bridge relayer to an EVM address.')]
class BridgeGasDropCommand extends Command
{
    public function handle(): int
    {
        $address = (string) $this->argument('address');

        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            $this->error("Not a valid EVM address: {$address}");

            return self::FAILURE;
        }

        $amount = (string) ($this->option('amount')
            ?? config('bridge.gas_drop.amount_cyber', '0.01'));

        if (bccomp($amount, '0', 18) <= 0) {
            $this->error('Amount must be > 0');

            return self::FAILURE;
        }

        $amountWei = explode('.', bcmul($amount, bcpow('10', '18')))[0];

        $this->line("Dropping {$amount} CYBER ({$amountWei} wei) → {$address}");

        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        $result = Process::path($hardhatDir)
            ->env([
                'CYBERIA_RPC_URL' => Environment::isProduction()
                    ? 'http://polygon-edge:8545'
                    : 'https://rpc.cyberia.church',
                'BRIDGE_RELAYER_PRIVATE_KEY' => app(BridgeRelayerService::class)->privateKey() ?? '',
                'RECIPIENT' => $address,
                'AMOUNT_WEI' => $amountWei,
            ])
            ->timeout(120)
            ->run([
                'npx', 'tsx', '-e',
                <<<'JS'
import 'dotenv/config';
import { ethers } from 'ethers';
const pk = process.env.BRIDGE_RELAYER_PRIVATE_KEY!;
const url = process.env.CYBERIA_RPC_URL || 'https://rpc.cyberia.church';
const network = new ethers.Network('cyberia', 49406);
const provider = new ethers.JsonRpcProvider(url, network, { staticNetwork: network });
const wallet = new ethers.Wallet(pk.startsWith('0x') ? pk : '0x' + pk, provider);
const tx = await wallet.sendTransaction({ to: process.env.RECIPIENT!, value: BigInt(process.env.AMOUNT_WEI!) });
const r = await tx.wait();
console.log(JSON.stringify({ txHash: tx.hash, status: r?.status }));
JS,
            ]);

        if ($result->exitCode() !== 0) {
            $this->error('Gas drop failed:');
            $this->line($result->errorOutput());

            return self::FAILURE;
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $last = end($lines);
        $json = $last ? json_decode($last, true) : null;

        if (is_array($json) && ! empty($json['txHash'])) {
            $this->info("  → tx={$json['txHash']}");

            return self::SUCCESS;
        }

        $this->line($result->output());
        $this->warn('Could not parse relay output');

        return self::FAILURE;
    }
}
