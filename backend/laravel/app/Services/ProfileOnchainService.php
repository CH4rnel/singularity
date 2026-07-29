<?php

namespace App\Services;

use App\Models\User;
use App\Support\Environment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use kornrunner\Keccak;

/**
 * Reads and writes the CyberiaProfile contract (on-chain nicknames +
 * achievements). Reads are plain eth_calls against the Cyberia RPC; writes go
 * through crypto/hardhat/scripts/profile-admin.ts with the relayer key (the
 * contract owner), mirroring the bridge relay pattern.
 */
class ProfileOnchainService
{
    public function contractAddress(): ?string
    {
        $address = (string) config('services.profile.contract_address', '');

        return $address !== '' ? $address : null;
    }

    public function enabled(): bool
    {
        return $this->contractAddress() !== null;
    }

    /** Current on-chain nickname of a wallet, or null when unset. */
    public function nicknameOf(string $wallet): ?string
    {
        $result = $this->call('nicknameOf(address)', $this->encodeAddress($wallet));

        $nickname = $result === null ? null : $this->decodeString($result);

        return $nickname === '' ? null : $nickname;
    }

    /**
     * Refresh the cached canonical name without discarding a known value when
     * the RPC is temporarily unavailable.
     */
    public function syncNickname(User $user): ?string
    {
        if (! $user->wallet_address || ! $this->enabled()) {
            return $user->onchain_nickname;
        }

        $nickname = $this->nicknameOf($user->wallet_address);

        if ($nickname !== null && $nickname !== $user->onchain_nickname) {
            $user->forceFill(['onchain_nickname' => $nickname])->save();
        }

        return $nickname ?? $user->onchain_nickname;
    }

    /** Wallet currently owning a nickname, or null when free. */
    public function nicknameOwner(string $nickname): ?string
    {
        $key = Keccak::hash($nickname, 256);
        $result = $this->call('nicknameOwner(bytes32)', $key);

        if ($result === null || strlen($result) < 66) {
            return null;
        }

        $owner = '0x'.substr($result, 26, 40);

        return $owner === '0x'.str_repeat('0', 40) ? null : $owner;
    }

    /**
     * Achievement ids a wallet has earned, in unlock order.
     *
     * @return array<int, int>|null null = read failed / contract unset
     */
    public function achievementsOf(string $wallet): ?array
    {
        $result = $this->call('achievementsOf(address)', $this->encodeAddress($wallet));

        return $result === null ? null : $this->decodeUintArray($result);
    }

    /**
     * Award achievements to a wallet via the relayer. Returns the tx hash.
     *
     * @param  array<int, int>  $ids
     */
    public function award(string $wallet, array $ids): ?string
    {
        if ($ids === []) {
            return null;
        }

        return $this->runAdminScript([
            'award',
            (string) $this->contractAddress(),
            $wallet,
            ...array_map(strval(...), $ids),
        ]);
    }

    /** Set a wallet's nickname via the relayer. Returns the tx hash. */
    public function setNickname(string $wallet, string $nickname): ?string
    {
        return $this->runAdminScript([
            'set-nickname',
            (string) $this->contractAddress(),
            $wallet,
            $nickname,
        ]);
    }

    private function rpcUrl(): string
    {
        return (string) config('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');
    }

    /** eth_call helper: 4-byte selector from the signature + raw hex args. */
    private function call(string $signature, string $argsHex): ?string
    {
        $contract = $this->contractAddress();

        if ($contract === null) {
            return null;
        }

        $selector = substr(Keccak::hash($signature, 256), 0, 8);

        try {
            $response = Http::timeout(10)->post($this->rpcUrl(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_call',
                'params' => [
                    ['to' => $contract, 'data' => '0x'.$selector.$argsHex],
                    'latest',
                ],
            ]);

            $result = $response->json('result');

            return is_string($result) && str_starts_with($result, '0x') ? $result : null;
        } catch (\Throwable $e) {
            Log::warning('Profile onchain read failed', [
                'signature' => $signature,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function encodeAddress(string $wallet): string
    {
        return str_pad(strtolower(ltrim($wallet, '0x')), 64, '0', STR_PAD_LEFT);
    }

    /** Decode a single ABI-encoded dynamic string return value. */
    private function decodeString(string $result): ?string
    {
        $hex = ltrim(substr($result, 2), '0');
        $data = substr($result, 2);

        if (strlen($data) < 128) {
            return $hex === '' ? '' : null;
        }

        $length = (int) hexdec(substr($data, 64, 64));
        $decoded = hex2bin(substr($data, 128, $length * 2));

        return $decoded === false ? null : $decoded;
    }

    /**
     * Decode a single ABI-encoded uint256[] return value.
     *
     * @return array<int, int>
     */
    private function decodeUintArray(string $result): array
    {
        $data = substr($result, 2);

        if (strlen($data) < 128) {
            return [];
        }

        $count = (int) hexdec(substr($data, 64, 64));
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($data, 128 + $i * 64, 64);

            if (strlen($chunk) === 64) {
                $ids[] = (int) hexdec($chunk);
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function runAdminScript(array $args): ?string
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
                    'BRIDGE_RELAYER_PRIVATE_KEY' => app(BridgeRelayerService::class)->privateKey() ?? '',
                ])
                ->timeout(120)
                ->run(['npx', 'tsx', 'scripts/profile-admin.ts', ...$args]);
        } catch (\Throwable $e) {
            Log::error('Profile onchain write failed', [
                'action' => $args[0] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        Log::info('Profile onchain write', [
            'action' => $args[0] ?? null,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        if ($result->exitCode() !== 0) {
            return null;
        }

        foreach (array_reverse(explode("\n", trim($result->output()))) as $line) {
            $json = json_decode(trim($line), true);

            if (is_array($json) && ! empty($json['txHash'])) {
                return (string) $json['txHash'];
            }
        }

        return null;
    }
}
