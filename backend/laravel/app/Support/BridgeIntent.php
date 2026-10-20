<?php

namespace App\Support;

use App\Actions\Wallet\RecoverEvmAddress;
use App\Models\BridgeRequest;

/**
 * Who may say where a deposit is paid out (review finding B03).
 *
 * A deposit to the relayer's address is public the moment it reaches a
 * mempool: its hash, its sender and its amount are on any explorer. Verifying
 * that transfer proves that money arrived, and nothing about who is entitled
 * to name its recipient — so the first person to submit somebody else's hash
 * with their own address used to be paid with it. The unique index on the
 * source hash only made sure nobody could correct that afterwards.
 *
 * So the recipient has to be bound to the deposit by the depositor, and how
 * depends on what the source chain lets them say:
 *
 *   deposit_address — the route hands out one address per request, and the
 *                     recipient was committed before the address existed
 *                     (Yenten, Monero, Bitcoin, Litecoin).
 *   onchain         — the source transaction itself names the recipient and
 *                     the relay verifies it there (CYBER.sol redeemed through
 *                     CyberBridge carries its Solana recipient in the event).
 *   memo            — the transfer carries a comment naming the recipient
 *                     (TON; a TON Connect wallet cannot sign a bare message
 *                     this server can check, but every transfer can carry one).
 *   signature       — everything else: the sending key signs a statement
 *                     that names this deposit's recipient, on a wording no
 *                     other part of the site uses, so a login signature
 *                     cannot be replayed as one.
 *
 * A request without its proof is still recorded — money cannot be un-sent —
 * but it waits as `awaiting_recipient` and nothing pays it until the depositor
 * or an operator proves who it belongs to.
 */
final class BridgeIntent
{
    public const DEPOSIT_ADDRESS = 'deposit_address';

    public const ONCHAIN = 'onchain';

    public const MEMO = 'memo';

    public const SIGNATURE = 'signature';

    /** Recorded when an operator established ownership by hand. */
    public const OPERATOR = 'operator';

    private const ADDRESS_BOUND_TYPES = ['yenten', 'monero', 'bitcoin', 'litecoin'];

    public static function requirement(string $direction, string $token): string
    {
        $route = config('bridge.routes', [])[$direction] ?? [];
        $sourceType = config('bridge.chains', [])[$route['source_chain'] ?? '']['type'] ?? '';
        $model = config('bridge.tokens', [])[$token]['model'] ?? 'native';

        return match (true) {
            in_array($sourceType, self::ADDRESS_BOUND_TYPES, true) => self::DEPOSIT_ADDRESS,
            $direction === 'evm_to_sol' && $model === 'native' => self::ONCHAIN,
            $sourceType === 'ton' => self::MEMO,
            default => self::SIGNATURE,
        };
    }

    /**
     * The statement a sending wallet signs. Mirrored byte for byte by
     * `bridgeIntentMessage()` in resources/js/lib/bridgeIntent.ts.
     *
     * The reference is what makes it single-use: either the capacity
     * reservation the transfer was claimed under (signed before anything is
     * sent) or the source transaction hash itself (signed afterwards, to claim
     * a deposit that was never registered). Both can be consumed only once.
     */
    public static function message(
        string $direction,
        string $token,
        string $sender,
        string $recipient,
        string $reference,
    ): string {
        return implode("\n", [
            'Cyberia bridge: payout recipient',
            '',
            'I sent this deposit and I choose where it is paid out.',
            'This is not a login and it moves no funds.',
            '',
            'Direction: '.$direction,
            'Token: '.$token,
            'Sender: '.self::normalize($sender),
            'Recipient: '.self::normalize($recipient),
            'Reference: '.trim($reference),
        ]);
    }

    /**
     * The comment a TON deposit carries. Mirrored by `bridgeIntentMemo()`.
     */
    public static function memo(string $recipient): string
    {
        return 'cyberia-bridge:'.self::normalize($recipient);
    }

    /**
     * Does `$signature` prove that `$sender` signed `$message`?
     *
     * EVM: EIP-191 personal_sign. Solana: ed25519 over the UTF-8 bytes, as a
     * Wallet Standard `signMessage` returns it (base64, or base58 from older
     * adapters). Any other source chain cannot sign here and is never proven
     * by this path.
     */
    public static function signedBy(string $sourceChainType, string $sender, string $message, string $signature): bool
    {
        return match ($sourceChainType) {
            'evm' => self::evmSignedBy($sender, $message, $signature),
            'solana' => self::solanaSignedBy($sender, $message, $signature),
            default => false,
        };
    }

    /**
     * May a payout be sent for this request?
     *
     * Only the signature case needs a stored proof. A memo is checked when the
     * TON deposit is verified, an on-chain recipient when the redemption event
     * is, and an address-bound deposit is bound by the address it landed on.
     */
    public static function isProven(BridgeRequest $request): bool
    {
        if ($request->recipient_proven_at !== null) {
            return true;
        }

        return match (self::requirement($request->direction, $request->token)) {
            self::DEPOSIT_ADDRESS => $request->deposit_address !== null && $request->deposit_address !== '',
            self::ONCHAIN, self::MEMO => true,
            default => false,
        };
    }

    public static function normalize(string $address): string
    {
        $address = trim($address);

        return preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) ? strtolower($address) : $address;
    }

    private static function evmSignedBy(string $sender, string $message, string $signature): bool
    {
        $sender = self::normalize($sender);

        if (! preg_match('/^0x[0-9a-f]{40}$/D', $sender)) {
            return false;
        }

        $recovered = app(RecoverEvmAddress::class)->handle($message, $signature);

        return $recovered !== null && hash_equals($sender, strtolower($recovered));
    }

    private static function solanaSignedBy(string $sender, string $message, string $signature): bool
    {
        $publicKey = self::base58Decode(trim($sender));
        $bytes = base64_decode(trim($signature), true);

        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $bytes = self::base58Decode(trim($signature));
        }

        if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $bytes === null || strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($bytes, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /** Base58 (Bitcoin/Solana alphabet) to raw bytes, or null if it is not base58. */
    private static function base58Decode(string $input): ?string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

        if ($input === '' || strspn($input, $alphabet) !== strlen($input)) {
            return null;
        }

        $number = gmp_init(0);

        foreach (str_split($input) as $character) {
            $number = gmp_add(gmp_mul($number, 58), strpos($alphabet, $character));
        }

        $hex = gmp_strval($number, 16);
        $bytes = $hex === '0' ? '' : hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);

        return str_repeat("\x00", strspn($input, '1')).$bytes;
    }
}
