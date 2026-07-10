<?php

namespace App\Actions\Wallet;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use App\Models\WalletNonce;
use Elliptic\EC;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use kornrunner\Keccak;

class VerifyWalletSignature
{
    private EC $ec;

    public function __construct(
        private CreateTeam $createTeam
    ) {
        $this->ec = new EC('secp256k1');
    }

    public function handle(string $walletAddress, string $signature): User
    {
        $walletAddress = Str::lower($walletAddress);

        $nonce = WalletNonce::where('wallet_address', $walletAddress)->first();

        if (! $nonce || $nonce->isExpired()) {
            throw new \Exception('Invalid or expired nonce. Please request a new signature.');
        }

        $message = "Sign this message to authenticate with your wallet. Nonce: {$nonce->nonce}";
        $recoveredAddress = $this->recoverAddress($message, $signature);

        if (Str::lower($recoveredAddress) !== $walletAddress) {
            throw new \Exception('Signature verification failed.');
        }

        $nonce->delete();

        return $this->authenticateOrRegister($walletAddress);
    }

    /**
     * Recover the signer address of an arbitrary personal_sign message.
     * Public so callers that verify a different message (e.g. wallet attach,
     * whose message prefix differs from login) can reuse the same crypto.
     */
    public function recover(string $message, string $signature): string
    {
        return $this->recoverAddress($message, $signature);
    }

    private function recoverAddress(string $message, string $signature): string
    {
        $msgHash = $this->hashPersonalMessage($message);

        $sig = $this->parseSignature($signature);
        if (! $sig) {
            throw new \Exception('Invalid signature format.');
        }

        $recoveryParam = $this->getRecoveryParam($sig);

        $keyPair = $this->ec->keyFromPublic($this->ec->recoverPubKey($msgHash, $sig, $recoveryParam)->encode('array'));

        $publicKey = $keyPair->getPublic()->encode('array');
        if (count($publicKey) === 65 && $publicKey[0] === 0x04) {
            array_shift($publicKey);
        }

        return '0x'.substr($this->keccak256($publicKey), 24);
    }

    private function parseSignature(string $signature): ?array
    {
        $sig = trim($signature);

        if (str_starts_with($sig, '0x')) {
            $sig = substr($sig, 2);
        }

        if (strlen($sig) !== 130) {
            return null;
        }

        return [
            'r' => '0x'.substr($sig, 0, 64),
            's' => '0x'.substr($sig, 64, 64),
            // Trailing byte is v (recovery id). Normalize to 0..3: wallets
            // emit 27/28 (EIP-191) or occasionally EIP-155 chain-encoded
            // values; the low bit carries the parity we need.
            'v' => hexdec(substr($sig, 128, 2)),
        ];
    }

    /**
     * Recovery id (0..3) from the signature's v byte. The recovery id MUST
     * come from the signature — the pubkey-recovery math succeeds for the
     * wrong id too, so guessing (e.g. always 0) silently recovers a different
     * address for every v=28 signature.
     *
     * @param  array{r: string, s: string, v: int}  $sig
     */
    private function getRecoveryParam(array $sig): int
    {
        $v = $sig['v'];

        if ($v >= 27) {
            // EIP-191 (27/28) or EIP-155 (chainId*2 + 35/36); the parity is
            // the low bit either way.
            return ($v - 27) & 1;
        }

        return $v & 1;
    }

    private function hashPersonalMessage(string $message): string
    {
        $prefix = "\x19Ethereum Signed Message:\n".strlen($message);

        return $this->keccak256($prefix.$message);
    }

    private function keccak256(string|array $data): string
    {
        if (is_array($data)) {
            $data = implode('', array_map('chr', $data));
        }

        return Keccak::hash($data, 256);
    }

    private function authenticateOrRegister(string $walletAddress): User
    {
        return DB::transaction(function () use ($walletAddress) {
            $user = User::where('wallet_address', $walletAddress)->first();

            if (! $user) {
                $shortAddress = substr($walletAddress, 0, 10);
                $user = User::create([
                    'name' => "Wallet {$shortAddress}",
                    'email' => "wallet_{$walletAddress}@localhost",
                    'password' => null,
                    'wallet_address' => $walletAddress,
                ]);

                $this->createTeam->handle($user, "User's Team", isPersonal: true);
            }

            return $user;
        });
    }
}
