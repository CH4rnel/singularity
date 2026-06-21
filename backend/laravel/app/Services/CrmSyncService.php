<?php

namespace App\Services;

use App\Actions\Wallet\ReadCyberSolBalance;
use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pulls CRM contacts out of the ecosystem's own data sources and keeps their
 * on-chain balances fresh.
 *
 * Sources, in order of trust: platform users (the `users` table), bridge
 * senders/recipients (`bridge_requests`), and the Telegram whale gate
 * (`tg_sol_wallets`, owned by the bot — reads are defensive). Every importer
 * routes through {@see upsertByIdentity()} so the same person discovered via
 * several channels collapses into one contact rather than duplicating.
 */
class CrmSyncService
{
    public function __construct(private ReadCyberSolBalance $readCyberSol) {}

    /**
     * Run every importer and return per-source counts.
     *
     * @return array{platform: int, bridge: int, whales: int}
     */
    public function syncAll(): array
    {
        return [
            'platform' => $this->importPlatformUsers(),
            'bridge' => $this->importBridgeUsers(),
            'whales' => $this->importWhales(),
        ];
    }

    /**
     * Mirror platform users (web3 or email accounts) into CRM contacts.
     */
    public function importPlatformUsers(): int
    {
        $count = 0;

        User::query()
            ->select(['id', 'name', 'email', 'wallet_address', 'solana_wallet_address'])
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$count) {
                foreach ($users as $user) {
                    $hasWallet = $user->wallet_address || $user->solana_wallet_address;

                    $this->upsertByIdentity([
                        'user_id' => $user->id,
                        'evm_address' => $user->wallet_address,
                        'solana_address' => $user->solana_wallet_address,
                        'email' => $user->email,
                    ], [
                        'name' => $user->name,
                        'email' => $user->email,
                        'evm_address' => $this->normalizeEvm($user->wallet_address),
                        'solana_address' => $user->solana_wallet_address,
                        'user_id' => $user->id,
                        'source' => 'platform',
                        'type' => $hasWallet ? 'holder' : 'lead',
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Pull every distinct address that has ever bridged into CRM contacts.
     */
    public function importBridgeUsers(): int
    {
        $count = 0;

        $addresses = BridgeRequest::query()
            ->select(['sender_address', 'recipient_address'])
            ->get()
            ->flatMap(fn (BridgeRequest $r) => [$r->sender_address, $r->recipient_address])
            ->filter()
            ->unique()
            ->values();

        foreach ($addresses as $address) {
            $isEvm = str_starts_with((string) $address, '0x');

            $this->upsertByIdentity(
                $isEvm
                    ? ['evm_address' => $address]
                    : ['solana_address' => $address],
                [
                    'evm_address' => $isEvm ? $this->normalizeEvm($address) : null,
                    'solana_address' => $isEvm ? null : $address,
                    'source' => 'bridge',
                    'type' => 'holder',
                ],
                onlyFillMissing: true,
            );
            $count++;
        }

        return $count;
    }

    /**
     * Import verified whales from the Telegram bot's `tg_sol_wallets` table.
     *
     * The table is created and owned by the bot; if it is absent (e.g. on a
     * fresh install or in tests) we simply import nothing.
     */
    public function importWhales(): int
    {
        if (! Schema::hasTable('tg_sol_wallets')) {
            return 0;
        }

        $decimals = (int) config('services.cyber_sol.decimals', 6);
        $count = 0;

        DB::table('tg_sol_wallets')
            ->where('is_whale', 1)
            ->whereNotNull('solana_address')
            ->orderBy('solana_address')
            ->each(function ($row) use ($decimals, &$count) {
                $balance = isset($row->balance_raw)
                    ? bcdiv((string) $row->balance_raw, bcpow('10', (string) $decimals), $decimals)
                    : null;

                $this->upsertByIdentity(
                    ['solana_address' => $row->solana_address],
                    [
                        'solana_address' => $row->solana_address,
                        'telegram' => isset($row->tg_user_id) ? (string) $row->tg_user_id : null,
                        'source' => 'whale_bot',
                        'type' => 'whale',
                        'cyber_sol_balance' => $balance,
                    ],
                );
                $count++;
            });

        return $count;
    }

    /**
     * Refresh cached on-chain balances for contacts that hold an address,
     * promoting any that now clear the whale threshold.
     *
     * @return int Number of contacts whose balances were refreshed.
     */
    public function refreshBalances(int $limit = 100): int
    {
        $threshold = (int) config('services.cyber_sol.whale_threshold');
        $rpc = app(CyberiaRpcService::class);
        $count = 0;

        CrmContact::query()
            ->where(function ($q) {
                $q->whereNotNull('evm_address')->orWhereNotNull('solana_address');
            })
            ->orderBy('last_synced_at')
            ->limit($limit)
            ->get()
            ->each(function (CrmContact $contact) use ($rpc, $threshold, &$count) {
                $updates = ['last_synced_at' => now()];

                if ($contact->evm_address) {
                    $wei = $rpc->nativeBalanceWei($contact->evm_address);
                    if ($wei !== null) {
                        $updates['cyber_balance'] = bcdiv($wei, bcpow('10', '18'), 18);
                    }
                }

                if ($contact->solana_address) {
                    try {
                        $balance = $this->readCyberSol->handle($contact->solana_address);
                        $updates['cyber_sol_balance'] = $balance['amount'];

                        if ($this->shouldBeWhale((float) $balance['amount'], $threshold) && $contact->type !== 'whale') {
                            $updates['type'] = 'whale';
                        }
                    } catch (\Throwable $e) {
                        Log::warning('CrmSync: CYBER.sol balance lookup failed', [
                            'contact_id' => $contact->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $contact->update($updates);
                $count++;
            });

        return $count;
    }

    /**
     * Decide whether a CYBER.sol balance qualifies a contact as a whale.
     */
    public function shouldBeWhale(?float $cyberSolBalance, int $threshold): bool
    {
        return $cyberSolBalance !== null && $cyberSolBalance >= $threshold;
    }

    /**
     * Find an existing contact matching any of the identity keys, or create a
     * new one, then apply the given attributes.
     *
     * @param  array<string, mixed>  $identity  Candidate match keys (user_id, evm_address, solana_address, email).
     * @param  array<string, mixed>  $attributes  Values to write onto the contact.
     * @param  bool  $onlyFillMissing  When true, only set attributes the contact does not already have.
     */
    private function upsertByIdentity(array $identity, array $attributes, bool $onlyFillMissing = false): CrmContact
    {
        $identity = array_filter($identity, fn ($v) => $v !== null && $v !== '');

        $query = CrmContact::query();
        foreach ($identity as $column => $value) {
            $needle = $column === 'evm_address' ? $this->normalizeEvm($value) : $value;
            $query->orWhere($column, $needle);
        }

        $contact = $identity === [] ? null : $query->first();
        $contact ??= new CrmContact;

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($onlyFillMissing && $contact->exists && ! empty($contact->{$key})) {
                continue;
            }
            $contact->{$key} = $value;
        }

        $contact->save();

        return $contact;
    }

    private function normalizeEvm(?string $address): ?string
    {
        return $address ? strtolower($address) : null;
    }
}
