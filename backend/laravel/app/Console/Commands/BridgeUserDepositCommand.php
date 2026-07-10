<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserDepositAddress;
use App\Services\UserDepositAddressService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bridge:user-deposit
    {chain? : bitcoin|litecoin|yenten|monero (aliases: btc, ltc, ytn, xmr)}
    {--user= : User id to show the personal deposit address for}
    {--find= : Reverse lookup — which user owns this deposit address}
    {--wif : Also print the WIF spending key (SENSITIVE: for sweeping only, never log or commit)}')]
#[Description('Personal per-user deposit addresses: derive/show, reverse-lookup an incoming deposit, or export the sweep WIF.')]
class BridgeUserDepositCommand extends Command
{
    private const ALIASES = [
        'btc' => 'bitcoin',
        'ltc' => 'litecoin',
        'ytn' => 'yenten',
        'xmr' => 'monero',
    ];

    public function handle(UserDepositAddressService $service): int
    {
        if ($find = (string) $this->option('find')) {
            return $this->find($find);
        }

        $chain = strtolower((string) $this->argument('chain'));
        $chain = self::ALIASES[$chain] ?? $chain;

        if (! in_array($chain, UserDepositAddressService::CHAINS, true)) {
            $this->error('Pass a chain (bitcoin|litecoin|yenten|monero) or --find=<address>.');

            return self::FAILURE;
        }

        $userId = (int) $this->option('user');

        if ($userId < 1) {
            $this->error('Pass --user=<id>.');

            return self::FAILURE;
        }

        $derived = $service->derive($chain, $userId);

        if ($derived === null) {
            $this->error("No operator setup for {$chain}: configure its hd_seed (and, for monero, the main deposit address).");

            return self::FAILURE;
        }

        $stored = UserDepositAddress::query()
            ->where('user_id', $userId)
            ->where('chain', $chain)
            ->value('address');

        $this->line("User #{$userId} on {$chain}: ".($stored ?? $derived));

        if ($stored !== null && $stored !== $derived) {
            // The stored address is what the user was shown and is the one
            // being honored; a mismatch means the seed changed since issuance.
            $this->warn("Seed mismatch: current config derives {$derived}, but the issued (honored) address is {$stored}. A WIF from the current seed cannot spend it.");
        }

        if ($this->option('wif')) {
            $wif = $service->wif($chain, $userId);

            if ($wif === null) {
                $this->warn('No WIF: Monero integrated-address deposits land directly in the main wallet.');
            } else {
                $this->warn('WIF below — for sweeping only. Never log, paste into chats, or commit it.');
                $this->line($wif);
            }
        }

        return self::SUCCESS;
    }

    private function find(string $address): int
    {
        $row = UserDepositAddress::query()->where('address', $address)->first();

        if (! $row) {
            $this->info('No user owns this address.');

            return self::SUCCESS;
        }

        $user = User::find($row->user_id);

        $this->line("Address {$address}");
        $this->line("Chain:   {$row->chain}");
        $this->line("User:    #{$row->user_id}".($user?->name ? " ({$user->name})" : ''));
        $this->line('EVM:     '.($user?->wallet_address ?? '—'));

        return self::SUCCESS;
    }
}
