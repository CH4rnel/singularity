<?php

namespace App\Services;

use App\Models\GasSponsorship;
use App\Models\User;
use App\Support\Environment;
use Elliptic\EC;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use kornrunner\Keccak;

/**
 * Sponsored fees for the unified wallet, on Cyberia.
 *
 * A fee is payable only in CYBER, so an address holding USDC and nothing else
 * cannot move its USDC — the one failure the wallet's own screens already name
 * as its own state. The answer is not a smart account and not a meta
 * transaction: the CyberiaGasStation contract hands that address a small amount
 * of CYBER and the user then signs their own transaction exactly as before.
 * Which is why this covers sends, swaps, token transfers, mints, votes and
 * every action nobody has written yet — it changes nothing about signing.
 *
 * Two halves, on purpose:
 *
 *   The contract answers "what will the station do at most" — a fixed drip, to
 *   an address below a balance ceiling, once per cooldown, under a daily cap.
 *   Those bounds hold against anything on this server, a stolen sponsor key
 *   included, because the tank is not the key's to empty.
 *
 *   This class answers "who deserves it", which is a question about the world
 *   and cannot be asked on chain: does the address own anything here, has it
 *   already asked five times from one address today, is there an account
 *   behind it. Both refuse in the same vocabulary, so the wallet renders one
 *   reason whichever half produced it.
 *
 * The gate is the problem restated: sponsor an address that already owns
 * something on Cyberia. An empty address has nothing to move and therefore no
 * fee to pay, and a bot farming this would have to fund every sybil address
 * with assets worth more than the drip before it could ask for one.
 */
class GasSponsorService
{
    /** Eligible. */
    public const OK = 'ok';

    /** No station configured, no key, or switched off. */
    public const DISABLED = 'disabled';

    /** The owner stopped the station. */
    public const PAUSED = 'paused';

    /** The tank cannot cover one more drip. */
    public const EMPTY_TANK = 'empty';

    /** The address can already pay a fee — the point at which this stops. */
    public const HAS_GAS = 'hasGas';

    /** Sponsored recently; the contract's own cooldown. */
    public const COOLING_DOWN = 'coolingDown';

    /** The station has spent its day. */
    public const DAILY_CAP = 'dailyCap';

    /** Owns nothing here, so has nothing that needs moving. */
    public const HOLDS_NOTHING = 'holdsNothing';

    /** This server's own per-caller quota, above the contract's. */
    public const QUOTA = 'quota';

    /** The chain or the index could not be read. Fails closed. */
    public const UNREADABLE = 'unreadable';

    /** Contract revert strings, in this class's vocabulary. */
    private const CONTRACT_REASONS = [
        'paused' => self::PAUSED,
        'station empty' => self::EMPTY_TANK,
        'has gas' => self::HAS_GAS,
        'cooling down' => self::COOLING_DOWN,
        'daily cap reached' => self::DAILY_CAP,
    ];

    /** How long the index's answer about one address is reused. */
    private const HOLDINGS_CACHE_SECONDS = 60;

    public function enabled(): bool
    {
        return (bool) config('wallet.sponsor.enabled')
            && $this->station() !== null
            && (string) config('wallet.sponsor.private_key') !== '';
    }

    /** The CyberiaGasStation address, or null when there is none to talk to. */
    public function station(): ?string
    {
        $address = trim((string) config('wallet.sponsor.station'));

        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1 ? $address : null;
    }

    /**
     * What the station is and where it stands, read from the station itself.
     *
     * Null means it could not be read at all — which the caller must treat as
     * "unavailable", never as "empty": the difference between a node having a
     * bad minute and a tank that is actually dry matters to whoever is on call.
     *
     * @return array{tank: string, drip: string, ceiling: string, cooldown: int, dailyCap: string, remainingToday: string, served: int, spent: string, paused: bool}|null
     */
    public function summary(): ?array
    {
        if ($this->station() === null) {
            return null;
        }

        return Cache::remember(
            'wallet.gas-station.summary',
            (int) config('wallet.sponsor.cache_seconds', 30),
            function (): ?array {
                $result = $this->ethCall('summary()', '');

                if ($result === null) {
                    return null;
                }

                $words = $this->words($result);

                if (count($words) < 9) {
                    return null;
                }

                return [
                    'tank' => $this->toDecimal($words[0]),
                    'drip' => $this->toDecimal($words[1]),
                    'ceiling' => $this->toDecimal($words[2]),
                    'cooldown' => (int) $this->toDecimal($words[3]),
                    'dailyCap' => $this->toDecimal($words[4]),
                    'remainingToday' => $this->toDecimal($words[5]),
                    'served' => (int) $this->toDecimal($words[6]),
                    'spent' => $this->toDecimal($words[7]),
                    'paused' => $this->toDecimal($words[8]) !== '0',
                ];
            },
        );
    }

    /**
     * Whether one address may be sponsored right now, and why not when it may not.
     *
     * The contract is asked first, because it is the half that can refuse for
     * reasons this server cannot see (someone else's operator, a cooldown set
     * on chain), and it costs one eth_call. Only then does the gate ask the
     * index what the address owns, which costs an HTTP round trip.
     *
     * @return array{ok: bool, reason: string, cooldownRemaining: int, grounds: string|null}
     */
    public function eligibility(string $address, ?string $ip = null): array
    {
        $address = Str::lower($address);

        if (! $this->enabled()) {
            return $this->refusal(self::DISABLED);
        }

        $onchain = $this->canClaim($address);

        if ($onchain === null) {
            return $this->refusal(self::UNREADABLE);
        }

        if (! $onchain['ok']) {
            return $this->refusal(
                self::CONTRACT_REASONS[$onchain['reason']] ?? self::UNREADABLE,
                $this->cooldownRemaining($address),
            );
        }

        if (! $this->withinQuota($ip)) {
            return $this->refusal(self::QUOTA);
        }

        $grounds = $this->grounds($address);

        if ($grounds === 'none') {
            return $this->refusal(self::HOLDS_NOTHING);
        }

        // An index that cannot be read is not evidence that an address owns
        // nothing. Saying so would tell every user of a healthy wallet that
        // they own nothing, so the gate fails closed and says why.
        if ($grounds === 'error') {
            return $this->refusal(self::UNREADABLE);
        }

        return [
            'ok' => true,
            'reason' => self::OK,
            'cooldownRemaining' => 0,
            'grounds' => $grounds,
        ];
    }

    /**
     * Pay one address's fees.
     *
     * Synchronous by design: the wallet is asking because a person is waiting
     * to sign something, and at Cyberia's block time the round trip is a second
     * or two. The lock is there because two tabs asking at once would otherwise
     * spend two transactions to be refused by the cooldown once.
     *
     * @return array{ok: bool, reason: string, txHash?: string, amount?: string}
     */
    public function sponsor(string $address, ?string $ip = null): array
    {
        $address = Str::lower($address);
        $lock = Cache::lock("wallet.gas-sponsor:{$address}", 60);

        if (! $lock->get()) {
            return ['ok' => false, 'reason' => self::COOLING_DOWN];
        }

        try {
            $eligibility = $this->eligibility($address, $ip);

            if (! $eligibility['ok']) {
                return ['ok' => false, 'reason' => $eligibility['reason']];
            }

            $result = $this->runClaim($address);

            if ($result === null) {
                return ['ok' => false, 'reason' => self::UNREADABLE];
            }

            if (($result['status'] ?? '') === 'refused') {
                return [
                    'ok' => false,
                    'reason' => self::CONTRACT_REASONS[$result['reason'] ?? ''] ?? self::UNREADABLE,
                ];
            }

            if (($result['status'] ?? '') !== 'success') {
                return ['ok' => false, 'reason' => self::UNREADABLE];
            }

            GasSponsorship::create([
                'address' => $address,
                'amount_wei' => (string) ($result['amount'] ?? '0'),
                'tx_hash' => $result['txHash'] ?? null,
                'grounds' => $eligibility['grounds'],
                'ip_hash' => $this->ipHash($ip),
            ]);

            // The tank just moved, and the summary is what a status page shows.
            Cache::forget('wallet.gas-station.summary');

            return [
                'ok' => true,
                'reason' => self::OK,
                'txHash' => (string) ($result['txHash'] ?? ''),
                'amount' => (string) ($result['amount'] ?? '0'),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Everything the wallet needs to decide whether to offer the button, and
     * what to say when it cannot.
     *
     * @return array<string, mixed>
     */
    public function status(?string $address = null, ?string $ip = null): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false, 'chain' => 'cyberia'];
        }

        $summary = $this->summary();

        $status = [
            'enabled' => true,
            'chain' => 'cyberia',
            'station' => $this->station(),
            'drip' => $summary['drip'] ?? null,
            'ceiling' => $summary['ceiling'] ?? null,
            'cooldown' => $summary['cooldown'] ?? null,
            'tank' => $summary['tank'] ?? null,
            'paused' => $summary['paused'] ?? null,
            'served' => $summary['served'] ?? null,
            /*
             * The day's allowance, which is the one bound on this station with
             * a number to compare against: a tank has no capacity to draw a
             * gauge from, and "12 CYBER left" says nothing on its own about
             * whether the station is about to stop answering. Both halves are
             * sent because a share needs its denominator.
             */
            'dailyCap' => $summary['dailyCap'] ?? null,
            'remainingToday' => $summary['remainingToday'] ?? null,
            'spent' => $summary['spent'] ?? null,
        ];

        if ($address !== null && preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1) {
            $status['address'] = $this->eligibility($address, $ip);
        }

        return $status;
    }

    /**
     * What the address owns here, as grounds for sponsoring it.
     *
     * Any token balance counts, and so does an NFT: the index reports both in
     * one call. An account on the site counts too — it was earned by doing
     * something, which is the same kind of evidence a balance is.
     */
    private function grounds(string $address): string
    {
        if ((bool) config('wallet.sponsor.allow_site_accounts')
            && User::whereRaw('lower(wallet_address) = ?', [$address])->exists()) {
            return 'account';
        }

        if (! (bool) config('wallet.sponsor.require_holding')) {
            return 'open';
        }

        return $this->holdings($address);
    }

    /**
     * What the index says this address owns.
     *
     * Four answers, and the fourth is the one that matters: 'tokens', 'nft',
     * 'none', and 'error' for an index that could not be read — which is not
     * the same statement as "owns nothing" and must never be collapsed into it.
     */
    private function holdings(string $address): string
    {
        $key = "wallet.gas-holdings:{$address}";
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $answer = (function () use ($address): string {
            $api = (string) config('wallet.sponsor.explorer_api');

            try {
                $response = Http::timeout(10)->get($api, [
                    'module' => 'account',
                    'action' => 'tokenlist',
                    'address' => $address,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Gas sponsor holdings read failed', [
                    'error' => $e->getMessage(),
                ]);

                return 'error';
            }

            if (! $response->successful()) {
                return 'error';
            }

            $result = $response->json('result');

            // "No tokens found" comes back as status 0 with an empty or
            // absent result: an empty address, not a failed read.
            if (! is_array($result)) {
                return 'none';
            }

            $grounds = 'none';

            foreach ($result as $token) {
                if (! is_array($token)) {
                    continue;
                }

                $balance = (string) ($token['balance'] ?? '0');

                if (! preg_match('/^\d+$/', $balance) || bccomp($balance, '0') <= 0) {
                    continue;
                }

                $type = (string) ($token['type'] ?? 'ERC-20');

                if ($type === 'ERC-20') {
                    return 'tokens';
                }

                // Keep looking: a token balance is the stronger signal, so
                // an NFT only stands when nothing else does.
                $grounds = 'nft';
            }

            return $grounds;
        })();

        // An outage is not cached: pinning "error" for a minute would keep the
        // gate shut long after the index came back.
        if ($answer !== 'error') {
            Cache::put($key, $answer, self::HOLDINGS_CACHE_SECONDS);
        }

        return $answer;
    }

    /** This server's own quota, counted from rows so a cache flush cannot reset it. */
    private function withinQuota(?string $ip): bool
    {
        $since = now()->startOfDay();

        $total = GasSponsorship::where('created_at', '>=', $since)->count();

        if ($total >= (int) config('wallet.sponsor.daily_total', 500)) {
            return false;
        }

        $hash = $this->ipHash($ip);

        if ($hash === null) {
            return true;
        }

        return GasSponsorship::where('ip_hash', $hash)
            ->where('created_at', '>=', $since)
            ->count() < (int) config('wallet.sponsor.daily_per_ip', 5);
    }

    /**
     * Enough to recognise the same asker twice and useless for anything else.
     * Keyed with the app key so the table alone does not reverse it.
     */
    private function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === ''
            ? null
            : hash('sha256', $ip.'|'.config('app.key'));
    }

    /**
     * The operator EOA, derived from the sponsor key.
     *
     * Worth knowing separately from the tank: this key pays the gas that
     * *delivers* a drip, so a full station with a broke operator sponsors
     * nobody. `gas:station` watches both numbers for exactly that reason.
     *
     * Mirrors BridgeRelayerService::evmAddress(), which does the same
     * derivation for the shared relayer key.
     */
    public function operatorAddress(): ?string
    {
        $key = (string) config('wallet.sponsor.private_key');
        $hex = str_starts_with($key, '0x') ? substr($key, 2) : $key;

        if (preg_match('/^[0-9a-fA-F]{64}$/', $hex) !== 1) {
            return null;
        }

        return Cache::rememberForever(
            'wallet.gas-sponsor:operator:'.hash('sha256', $hex),
            function () use ($hex): string {
                $public = (new EC('secp256k1'))->keyFromPrivate($hex, 'hex')->getPublic(false, 'hex');
                // Strip the 0x04 uncompressed prefix, keccak256, last 20 bytes.
                $hash = Keccak::hash((string) hex2bin(substr($public, 2)), 256);

                return '0x'.substr($hash, -40);
            },
        );
    }

    /** Native CYBER balance of an address, in wei, or null when unreadable. */
    public function nativeBalance(string $address): ?string
    {
        try {
            $response = Http::timeout(10)->post($this->rpcUrl(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getBalance',
                'params' => [$address, 'latest'],
            ]);

            $result = $response->json('result');

            return is_string($result) && str_starts_with($result, '0x')
                ? $this->toDecimal(substr($result, 2))
                : null;
        } catch (\Throwable $e) {
            Log::warning('Gas sponsor balance read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{ok: bool, reason: string}|null
     */
    private function canClaim(string $address): ?array
    {
        $result = $this->ethCall('canClaim(address)', $this->encodeAddress($address));

        if ($result === null) {
            return null;
        }

        $words = $this->words($result);

        if (count($words) < 2) {
            return null;
        }

        return [
            'ok' => $this->toDecimal($words[0]) !== '0',
            'reason' => $this->decodeStringAt($result, (int) $this->toDecimal($words[1])),
        ];
    }

    private function cooldownRemaining(string $address): int
    {
        $result = $this->ethCall('cooldownRemaining(address)', $this->encodeAddress($address));

        return $result === null ? 0 : (int) $this->toDecimal($this->words($result)[0] ?? '0');
    }

    /**
     * @return array{ok: bool, reason: string, cooldownRemaining: int, grounds: null}
     */
    private function refusal(string $reason, int $cooldownRemaining = 0): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'cooldownRemaining' => $cooldownRemaining,
            'grounds' => null,
        ];
    }

    /**
     * The one place a key is used. Mirrors the bridge and profile relays: the
     * signing happens in crypto/hardhat, and this process only reads a line of
     * JSON back.
     *
     * @return array<string, mixed>|null
     */
    private function runClaim(string $address): ?array
    {
        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');

        try {
            $result = Process::path($hardhatDir)
                ->env([
                    'EVM_RPC_URL' => $this->rpcUrl(),
                    'EVM_CHAIN_ID' => '49406',
                    'CYBERIA_RPC_URL' => $this->rpcUrl(),
                    'GAS_SPONSOR_PRIVATE_KEY' => (string) config('wallet.sponsor.private_key'),
                ])
                ->timeout(120)
                ->run([
                    'npx', 'tsx', 'scripts/gas-station.ts',
                    'claim', (string) $this->station(), $address,
                ]);
        } catch (\Throwable $e) {
            Log::error('Gas sponsorship failed', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $payload = $this->lastJsonLine($result->output());

        if ($payload === null) {
            Log::error('Gas sponsorship produced no result', [
                'address' => $address,
                'exit' => $result->exitCode(),
                'stderr' => Str::limit($result->errorOutput(), 500),
            ]);

            return null;
        }

        Log::info('Gas sponsorship', [
            'address' => $address,
            'status' => $payload['status'] ?? null,
            'txHash' => $payload['txHash'] ?? null,
        ]);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastJsonLine(string $output): ?array
    {
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && isset($decoded['status'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function rpcUrl(): string
    {
        return (string) config('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');
    }

    /** eth_call against the station: 4-byte selector from the signature + args. */
    private function ethCall(string $signature, string $argsHex): ?string
    {
        $station = $this->station();

        if ($station === null) {
            return null;
        }

        $selector = substr(Keccak::hash($signature, 256), 0, 8);

        try {
            $response = Http::timeout(10)->post($this->rpcUrl(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_call',
                'params' => [
                    ['to' => $station, 'data' => '0x'.$selector.$argsHex],
                    'latest',
                ],
            ]);

            $result = $response->json('result');

            return is_string($result) && preg_match('/^0x[0-9a-fA-F]*$/', $result) === 1
                ? $result
                : null;
        } catch (\Throwable $e) {
            Log::warning('Gas station read failed', [
                'signature' => $signature,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function encodeAddress(string $address): string
    {
        return str_pad(substr(Str::lower($address), 2), 64, '0', STR_PAD_LEFT);
    }

    /**
     * ABI return data as 32-byte words.
     *
     * @return array<int, string>
     */
    private function words(string $result): array
    {
        return str_split(substr($result, 2), 64) ?: [];
    }

    /** A dynamic string at a byte offset into the same return data. */
    private function decodeStringAt(string $result, int $offset): string
    {
        $data = substr($result, 2);
        $head = $offset * 2;

        if ($head + 64 > strlen($data)) {
            return '';
        }

        $length = (int) $this->toDecimal(substr($data, $head, 64));
        $decoded = hex2bin(substr($data, $head + 64, $length * 2));

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Hex word to a decimal string. Never an int: wei overflows PHP's integers
     * long before any interesting amount.
     */
    private function toDecimal(string $hex): string
    {
        $digits = ltrim(Str::lower($hex), '0');

        if ($digits === '') {
            return '0';
        }

        $decimal = '0';

        foreach (str_split($digits) as $digit) {
            $decimal = bcadd(bcmul($decimal, '16'), (string) hexdec($digit));
        }

        return $decimal;
    }
}
