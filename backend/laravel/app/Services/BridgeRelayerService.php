<?php

namespace App\Services;

use App\Support\Environment;
use Elliptic\EC;
use Illuminate\Support\Facades\Cache;
use kornrunner\Keccak;

/**
 * Single source of truth for the bridge relayer identity.
 *
 * The relayer EOA is also the owner of CyberBridge / wrapper-token contracts —
 * one private key serves all roles (deployer, owner, minter, relayer). Anything
 * needing the EVM address derives it lazily from BRIDGE_RELAYER_PRIVATE_KEY
 * (or DEPLOYER_PK as fallback) and caches the result.
 */
class BridgeRelayerService
{
    /**
     * Canonical resolver for the relayer private key.
     *
     * Lookup order:
     *   1. Laravel .env  (BRIDGE_RELAYER_PRIVATE_KEY or DEPLOYER_PK)
     *   2. crypto/hardhat/.env  (DEPLOYER_PK or BRIDGE_RELAYER_PRIVATE_KEY)
     *
     * The hardhat .env is the canonical source — keeping the key there means
     * we don't duplicate it across two .env files. Laravel reads it lazily.
     */
    public function privateKey(): ?string
    {
        $pk = (string) config('services.bridge.relayer_private_key');

        if ($pk !== '') {
            return $pk;
        }

        // Skip the on-disk fallback during unit/feature tests — tests should
        // set the key explicitly via config() to keep them hermetic.
        if (app()->runningUnitTests()) {
            return null;
        }

        return $this->readHardhatEnv('DEPLOYER_PK')
            ?? $this->readHardhatEnv('BRIDGE_RELAYER_PRIVATE_KEY');
    }

    /**
     * Resolve a key from crypto/hardhat/.env without touching global env state.
     */
    private function readHardhatEnv(string $key): ?string
    {
        $path = Environment::isProduction()
            ? '/singularity/crypto/hardhat/.env'
            : base_path('/../../crypto/hardhat/.env');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }

            [$k, $v] = explode('=', $line, 2);

            if (trim($k) !== $key) {
                continue;
            }

            $v = trim($v);

            // Strip surrounding quotes if present.
            if (strlen($v) >= 2) {
                $first = $v[0];
                $last = $v[strlen($v) - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $v = substr($v, 1, -1);
                }
            }

            return $v !== '' ? $v : null;
        }

        return null;
    }

    /**
     * EVM address of the relayer. Uses BRIDGE_RELAYER_ADDRESS if explicitly
     * set, otherwise derives from the private key once and caches forever.
     */
    public function evmAddress(): ?string
    {
        $explicit = (string) config('services.bridge.relayer_address');

        if ($explicit !== '') {
            return $explicit;
        }

        $pk = $this->privateKey();

        if ($pk === null) {
            return null;
        }

        $cacheKey = 'bridge:relayer:address:'.hash('sha256', $pk);

        return Cache::rememberForever($cacheKey, fn () => $this->deriveAddress($pk));
    }

    /**
     * Derive the EVM address from a hex secp256k1 private key.
     */
    private function deriveAddress(string $privateKey): string
    {
        $hex = ltrim($privateKey, '0');
        $hex = str_starts_with($privateKey, '0x') ? substr($privateKey, 2) : $privateKey;

        if (! preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new \RuntimeException('BRIDGE_RELAYER_PRIVATE_KEY must be 32 bytes hex');
        }

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($hex, 'hex');
        // Uncompressed public key — 65 bytes, first byte is 0x04 prefix.
        $pubHex = $key->getPublic(false, 'hex');
        // Strip the 04 prefix → 64 bytes (X|Y), keccak256, take last 20 bytes.
        $pubBytes = hex2bin(substr($pubHex, 2));
        $hashHex = Keccak::hash($pubBytes, 256);
        $address = '0x'.substr($hashHex, -40);

        return $this->toChecksum($address);
    }

    /**
     * EIP-55 checksum encoding.
     */
    private function toChecksum(string $address): string
    {
        $hex = strtolower(substr($address, 2));
        $hash = Keccak::hash($hex, 256);

        $out = '';

        for ($i = 0; $i < 40; $i++) {
            $out .= (hexdec($hash[$i]) >= 8) ? strtoupper($hex[$i]) : $hex[$i];
        }

        return '0x'.$out;
    }
}
