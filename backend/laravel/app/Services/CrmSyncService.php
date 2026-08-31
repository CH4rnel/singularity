<?php

namespace App\Services;

use App\Actions\Wallet\ReadCyberSolBalance;
use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmSync;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    /**
     * How the last holder scan went, for the run record.
     *
     * The scan is the one source that can fail on its own (a public RPC that
     * rate-limits answers with an empty result, not with an error), and it is
     * also the only one that can tell that somebody stopped holding. Both
     * facts belong to the run, so they are carried out of it rather than
     * being re-derived by a second scan.
     *
     * @var array{read: bool, sold: int}
     */
    private array $lastHolderScan = ['read' => false, 'sold' => 0];

    public function __construct(private ReadCyberSolBalance $readCyberSol) {}

    /**
     * Run every importer, record the run, and return per-source counts.
     *
     * The record is what lets the console print how old the base is. It is
     * written whatever happens: a run that read three sources out of four is
     * a fact an operator needs, and a date with nothing behind it is worse
     * than no date at all.
     *
     * @return array{platform: int, bridge: int, holders: int, whales: int, added: int, sold: int}
     */
    public function syncAll(string $trigger = 'schedule'): array
    {
        $run = CrmSync::create(['trigger' => $trigger, 'started_at' => now()]);
        $before = CrmContact::query()->count();
        $this->lastHolderScan = ['read' => false, 'sold' => 0];

        $counts = [
            'platform' => $this->importPlatformUsers(),
            'bridge' => $this->importBridgeUsers(),
            'holders' => $this->importHolders(),
            'whales' => $this->importWhales(),
        ];

        $added = max(0, CrmContact::query()->count() - $before);

        $run->update([
            'finished_at' => now(),
            'counts' => $counts,
            'added' => $added,
            'sold' => $this->lastHolderScan['sold'],
            'note' => $this->lastHolderScan['read'] ? null : CrmSync::NOTE_HOLDERS_UNREADABLE,
        ]);

        return $counts + ['added' => $added, 'sold' => $this->lastHolderScan['sold']];
    }

    /** The last recorded run, or null when the base has never been imported. */
    public function lastRun(): ?CrmSync
    {
        return CrmSync::query()->latest('id')->first();
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
     * Import every on-chain holder of the CYBER.sol mint as a CRM contact.
     *
     * This is the source that pulls the actual token holders (the pump.fun /
     * Solana crowd) — the other importers only see people who touched the
     * platform. We enumerate all of the mint's SPL token accounts with
     * `getProgramAccounts` (the only RPC that returns the full set;
     * `getTokenLargestAccounts` caps at 20), sum balances per owning wallet,
     * and upsert each. Existing contacts keep their name/source — we only
     * refresh the balance and may promote them to whale.
     *
     * Needs an RPC that allows `getProgramAccounts` (most providers do when a
     * mint `memcmp` filter is supplied; the public endpoint may rate-limit, in
     * which case this logs and imports nothing rather than breaking the sync).
     */
    public function importHolders(): int
    {
        $rpc = config('services.cyber_sol.rpc_url');
        $mint = (string) config('services.cyber_sol.mint');
        $decimals = (int) config('services.cyber_sol.decimals', 6);
        $threshold = (int) config('services.cyber_sol.whale_threshold');

        if (! $rpc || $mint === '') {
            return 0;
        }

        // Token accounts are owned by whichever token program minted them
        // (classic SPL Token vs Token-2022); detect it, default to classic.
        $program = $this->tokenProgramForMint($rpc, $mint)
            ?? 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

        try {
            $res = Http::timeout(60)->acceptJson()->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getProgramAccounts',
                'params' => [
                    $program,
                    [
                        'encoding' => 'jsonParsed',
                        'commitment' => 'confirmed',
                        'filters' => [
                            ['memcmp' => ['offset' => 0, 'bytes' => $mint]],
                        ],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('CrmSync: holder RPC request failed', ['error' => $e->getMessage()]);

            return 0;
        }

        $body = $res->json();
        if (! $res->successful() || isset($body['error'])) {
            Log::warning('CrmSync: getProgramAccounts failed — RPC may not allow it', [
                'status' => $res->status(),
                'error' => $body['error'] ?? null,
            ]);

            return 0;
        }

        // Sum raw balances per owning wallet (a wallet can hold several accounts).
        $byOwner = [];
        foreach (($body['result'] ?? []) as $acc) {
            $info = $acc['account']['data']['parsed']['info'] ?? null;
            $owner = $info['owner'] ?? null;
            $amount = $info['tokenAmount']['amount'] ?? null;
            if (! is_string($owner) || $amount === null) {
                continue;
            }
            $byOwner[$owner] = bcadd($byOwner[$owner] ?? '0', (string) $amount, 0);
        }

        $holders = [];
        $count = 0;
        foreach ($byOwner as $owner => $raw) {
            if (bccomp($raw, '0', 0) <= 0) {
                continue; // skip emptied / closed-balance accounts
            }
            $amount = bcdiv($raw, bcpow('10', (string) $decimals), $decimals);
            $this->upsertHolder($owner, $amount, $this->shouldBeWhale((float) $amount, $threshold));
            $holders[$owner] = true;
            $count++;
        }

        $this->lastHolderScan = ['read' => true, 'sold' => $this->markSellers($holders)];

        return $count;
    }

    /**
     * Write down the people who stopped holding.
     *
     * Selling used to be invisible: the scan lists the accounts that exist,
     * an emptied one is simply absent, and the contact kept the balance and
     * the whale tier it had on the day it was last seen — so the base slowly
     * filled with whales who hold nothing. Deleting them is not the answer
     * either; somebody who sold is a person we know, and the fact that they
     * sold is the most interesting thing on their record. They become a
     * **lead** whose status is **sold**, with the balance zeroed.
     *
     * Two guards, and both matter more than the feature:
     *
     * - **Nothing happens on an empty read.** A rate-limited RPC answers with
     *   an empty result rather than an error, and a market where literally
     *   everybody sold on the same afternoon has never happened. An empty
     *   scan means we did not look, not that nobody is there.
     * - **Only somebody who was actually seen holding.** A recorded balance
     *   above zero is the only evidence of holding this app keeps; a platform
     *   user typed `holder` for owning a wallet never held anything, and
     *   marking them as having sold would invent a story.
     *
     * A record an operator wrote off by hand keeps its status: `lost` is a
     * judgement about the person, `sold` is a fact about their balance, and
     * the judgement is the one a machine should not overwrite.
     *
     * @param  array<string, bool>  $holders  every address that holds right now
     * @return int how many people were written down as having sold
     */
    private function markSellers(array $holders): int
    {
        if ($holders === []) {
            return 0;
        }

        $sold = 0;

        CrmContact::query()
            ->whereNotNull('solana_address')
            ->where('cyber_sol_balance', '>', 0)
            ->chunkById(500, function ($contacts) use ($holders, &$sold) {
                foreach ($contacts as $contact) {
                    if (isset($holders[$contact->solana_address])) {
                        continue;
                    }

                    $contact->update([
                        'cyber_sol_balance' => '0',
                        'type' => 'lead',
                        'status' => $contact->status === 'lost' ? 'lost' : 'sold',
                        'last_synced_at' => now(),
                    ]);
                    $sold++;
                }
            });

        return $sold;
    }

    /**
     * Resolve the token program that owns a mint (classic SPL or Token-2022) by
     * reading the mint account's owner. Returns null if it can't be determined.
     */
    private function tokenProgramForMint(string $rpc, string $mint): ?string
    {
        try {
            $res = Http::timeout(15)->acceptJson()->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getAccountInfo',
                'params' => [$mint, ['encoding' => 'base64', 'commitment' => 'confirmed']],
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $owner = $res->successful() ? $res->json('result.value.owner') : null;

        return is_string($owner) ? $owner : null;
    }

    /**
     * Upsert a holder by Solana address: create new holders, refresh the balance
     * on existing contacts, and promote to whale when they qualify (never
     * downgrade, and never clobber an existing contact's name/source).
     */
    private function upsertHolder(string $solanaAddress, string $amount, bool $isWhale): void
    {
        $contact = CrmContact::query()->where('solana_address', $solanaAddress)->first() ?? new CrmContact;

        if (! $contact->exists) {
            $contact->solana_address = $solanaAddress;
            $contact->source = 'holder';
            $contact->type = $isWhale ? 'whale' : 'holder';
        } elseif ($isWhale && $contact->type !== 'whale') {
            $contact->type = 'whale';
        }

        $contact->cyber_sol_balance = $amount;
        $contact->last_synced_at = now();
        $contact->save();
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
