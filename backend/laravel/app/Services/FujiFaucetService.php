<?php

namespace App\Services;

use App\Models\FujiFaucetClaim;
use App\Support\Environment;
use App\Support\TokenAmount;
use Elliptic\EC;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use kornrunner\Keccak;

/**
 * Test CYBER on Cyberia Fuji (49407), for anyone who wants to try the chain.
 *
 * A plain faucet: an address, a cooldown, a per-IP quota and a daily total.
 * Unlike the gas station on the old chain there is no holding gate — on a
 * testnet the empty wallet is exactly the one that needs coins.
 *
 * The signing happens in crypto/hardhat (relay-native-transfer.ts), as it
 * does for the bridge and the gas station; this process only reads back the
 * hash it prints. The script prints that hash the moment the transaction is
 * in the pool, and a row is written for any hash it printed, receipt or not,
 * so a slow chain never makes one request pay twice.
 */
class FujiFaucetService
{
    public const OK = 'ok';

    /** No key, or switched off. */
    public const DISABLED = 'disabled';

    /** The faucet wallet cannot cover one more drip and its fee. */
    public const EMPTY_TANK = 'empty';

    /** The address already holds at least one drip. */
    public const HAS_GAS = 'hasGas';

    /** This address was served within the cooldown. */
    public const COOLING_DOWN = 'coolingDown';

    /** This IP has asked its share for the day. */
    public const QUOTA = 'quota';

    /** The faucet has served its day. */
    public const DAILY_CAP = 'dailyCap';

    /** Another drip is being sent right now; one key, one nonce at a time. */
    public const BUSY = 'busy';

    /** The chain could not be read, or the send failed. Fails closed. */
    public const UNREADABLE = 'unreadable';

    /** Subnet-EVM's RewardManager precompile: where fees go. */
    private const REWARD_MANAGER = '0x0200000000000000000000000000000000000004';

    /** currentRewardAddress() */
    private const CURRENT_REWARD_ADDRESS = '0xe915608b';

    private const TRANSFER_GAS = '21000';

    public function __construct(private FujiRpc $rpc) {}

    public function enabled(): bool
    {
        return (bool) config('fuji.faucet.enabled') && $this->faucetAddress() !== null;
    }

    /** The faucet's EOA, derived from its key; null when no usable key is set. */
    public function faucetAddress(): ?string
    {
        $key = (string) config('fuji.faucet.private_key');
        $hex = str_starts_with($key, '0x') ? substr($key, 2) : $key;

        if (preg_match('/^[0-9a-fA-F]{64}$/', $hex) !== 1) {
            return null;
        }

        return Cache::rememberForever(
            'fuji.faucet:address:'.hash('sha256', $hex),
            function () use ($hex): string {
                $public = (new EC('secp256k1'))->keyFromPrivate($hex, 'hex')->getPublic(false, 'hex');
                $hash = Keccak::hash((string) hex2bin(substr($public, 2)), 256);

                return '0x'.substr($hash, -40);
            },
        );
    }

    /** One drip, in wei. */
    public function dripWei(): string
    {
        return TokenAmount::toRaw((string) config('fuji.faucet.drip_cyber', '10000'), 18);
    }

    /**
     * Everything the page shows: the chain's live fee and where fees go, the
     * faucet's tank, and — when an address is named — whether it can claim.
     *
     * @return array{
     *     enabled: bool,
     *     chainId: int,
     *     gasPrice: string|null,
     *     feeRecipient: string|null,
     *     faucet: string|null,
     *     drip: string,
     *     cooldownHours: int,
     *     tank: string|null,
     *     address?: array{ok: bool, reason: string, cooldownRemaining: int, balance: string|null}
     * }
     */
    public function status(?string $address = null, ?string $ip = null): array
    {
        $network = $this->network();
        $enabled = $this->enabled();

        $status = [
            'enabled' => $enabled,
            'chainId' => $this->rpc->chainId(),
            'gasPrice' => $network['gasPrice'],
            'feeRecipient' => $network['feeRecipient'],
            'faucet' => $enabled ? $this->faucetAddress() : null,
            'drip' => $this->dripWei(),
            'cooldownHours' => (int) config('fuji.faucet.cooldown_hours', 24),
            'tank' => $enabled ? $this->tank() : null,
        ];

        if ($address !== null && preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1) {
            $status['address'] = $this->eligibility($address, $ip);
        }

        return $status;
    }

    /**
     * Whether one address may claim right now, and why not when it may not.
     * The cheap checks (rows in the database) go before the chain reads.
     *
     * @return array{ok: bool, reason: string, cooldownRemaining: int, balance: string|null}
     */
    public function eligibility(string $address, ?string $ip = null): array
    {
        $address = Str::lower($address);

        if (! $this->enabled()) {
            return $this->refusal(self::DISABLED);
        }

        $cooldownRemaining = $this->cooldownRemaining($address);

        if ($cooldownRemaining > 0) {
            return $this->refusal(self::COOLING_DOWN, $cooldownRemaining);
        }

        $ipHash = $this->ipHash($ip);

        if ($ipHash !== null
            && FujiFaucetClaim::where('ip_hash', $ipHash)->where('created_at', '>=', now()->subDay())->count()
                >= (int) config('fuji.faucet.daily_per_ip', 3)) {
            return $this->refusal(self::QUOTA);
        }

        if (FujiFaucetClaim::where('created_at', '>=', now()->subDay())->count()
            >= (int) config('fuji.faucet.daily_total', 20)) {
            return $this->refusal(self::DAILY_CAP);
        }

        $balance = $this->balance($address);

        if ($balance === null) {
            return $this->refusal(self::UNREADABLE);
        }

        if (bccomp($balance, $this->dripWei()) >= 0) {
            return $this->refusal(self::HAS_GAS, 0, $balance);
        }

        $tank = $this->tank();
        $gasPrice = $this->network()['gasPrice'];

        if ($tank === null || $gasPrice === null) {
            return $this->refusal(self::UNREADABLE, 0, $balance);
        }

        // The drip and the fee for sending it, with room for the fee to rise.
        $needed = bcadd($this->dripWei(), bcmul(bcmul(self::TRANSFER_GAS, $gasPrice), '2'));

        if (bccomp($tank, $needed) < 0) {
            return $this->refusal(self::EMPTY_TANK, 0, $balance);
        }

        return ['ok' => true, 'reason' => self::OK, 'cooldownRemaining' => 0, 'balance' => $balance];
    }

    /**
     * Send one drip.
     *
     * Synchronous: a person is waiting on the page, and the L1 makes a block
     * as soon as a transaction arrives. One lock for the whole faucet because
     * every drip comes from the same key.
     *
     * @return array{ok: bool, reason: string, txHash?: string, amount?: string, confirmed?: bool}
     */
    public function claim(string $address, ?string $ip = null): array
    {
        $address = Str::lower($address);
        $lock = Cache::lock('fuji.faucet:send', 120);

        try {
            $lock->block(20);
        } catch (LockTimeoutException) {
            return ['ok' => false, 'reason' => self::BUSY];
        }

        try {
            $eligibility = $this->eligibility($address, $ip);

            if (! $eligibility['ok']) {
                return ['ok' => false, 'reason' => $eligibility['reason']];
            }

            $amount = $this->dripWei();
            $sent = $this->send($address, $amount);

            if ($sent === null) {
                return ['ok' => false, 'reason' => self::UNREADABLE];
            }

            FujiFaucetClaim::create([
                'address' => $address,
                'amount_wei' => $amount,
                'tx_hash' => $sent['txHash'],
                'ip_hash' => $this->ipHash($ip),
            ]);

            Cache::forget('fuji.faucet:tank');

            return [
                'ok' => true,
                'reason' => self::OK,
                'txHash' => $sent['txHash'],
                'amount' => $amount,
                'confirmed' => $sent['confirmed'],
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * The live gas price and the fee recipient, read together and briefly
     * cached: every visitor's page asks for them.
     *
     * @return array{gasPrice: string|null, feeRecipient: string|null}
     */
    private function network(): array
    {
        return Cache::remember('fuji.faucet:network', 15, function (): array {
            $gasPrice = $this->rpc->quantity('eth_gasPrice');
            $reward = $this->rpc->call(self::REWARD_MANAGER, self::CURRENT_REWARD_ADDRESS);

            $recipient = is_string($reward) && strlen($reward) >= 42
                ? '0x'.substr($reward, -40)
                : null;

            return [
                'gasPrice' => $gasPrice,
                // A zero reward address means fees go to each block's producer.
                'feeRecipient' => $recipient !== null && $recipient !== '0x'.str_repeat('0', 40) ? $recipient : null,
            ];
        });
    }

    /** The faucet wallet's balance in wei, or null when unreadable. */
    private function tank(): ?string
    {
        $faucet = $this->faucetAddress();

        if ($faucet === null) {
            return null;
        }

        return Cache::remember('fuji.faucet:tank', 15, fn (): ?string => $this->balance($faucet));
    }

    private function balance(string $address): ?string
    {
        return $this->rpc->balance($address);
    }

    private function cooldownRemaining(string $address): int
    {
        $last = FujiFaucetClaim::where('address', $address)->latest()->first();

        if ($last === null) {
            return 0;
        }

        $until = $last->created_at->addHours((int) config('fuji.faucet.cooldown_hours', 24));

        return max(0, (int) ceil(now()->diffInSeconds($until, false)));
    }

    /**
     * @return array{ok: bool, reason: string, cooldownRemaining: int, balance: string|null}
     */
    private function refusal(string $reason, int $cooldownRemaining = 0, ?string $balance = null): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'cooldownRemaining' => $cooldownRemaining,
            'balance' => $balance,
        ];
    }

    /**
     * Run the transfer and read back its hash.
     *
     * @return array{txHash: string, confirmed: bool}|null
     */
    private function send(string $address, string $amountWei): ?array
    {
        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        try {
            $result = Process::path($hardhatDir)
                ->env([
                    'BRIDGE_RELAYER_PRIVATE_KEY' => (string) config('fuji.faucet.private_key'),
                    'EVM_RPC_URL' => $this->rpc->url(),
                    'EVM_CHAIN_ID' => (string) $this->rpc->chainId(),
                    'EVM_NETWORK_NAME' => 'cyberia-fuji',
                ])
                ->timeout(90)
                ->run(['npx', 'tsx', 'scripts/relay-native-transfer.ts', $address, $amountWei]);
        } catch (\Throwable $e) {
            Log::error('Fuji faucet send failed', ['address' => $address, 'error' => $e->getMessage()]);

            return null;
        }

        $confirmed = null;
        $broadcast = null;

        foreach (explode("\n", trim($result->output())) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && is_string($decoded['txHash'] ?? null)) {
                $confirmed = $decoded['txHash'];
            }

            if (is_array($decoded) && is_string($decoded['broadcastTxHash'] ?? null)) {
                $broadcast = $decoded['broadcastTxHash'];
            }
        }

        $hash = $confirmed ?? $broadcast;

        Log::info('Fuji faucet drip', [
            'address' => $address,
            'txHash' => $hash,
            'confirmed' => $confirmed !== null,
            'exit' => $result->exitCode(),
        ]);

        if ($hash === null) {
            Log::error('Fuji faucet send produced no hash', [
                'address' => $address,
                'stderr' => Str::limit($result->errorOutput(), 500),
            ]);

            return null;
        }

        return ['txHash' => $hash, 'confirmed' => $confirmed !== null];
    }

    private function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === ''
            ? null
            : hash('sha256', $ip.'|'.config('app.key'));
    }
}
