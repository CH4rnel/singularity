<?php

namespace App\Actions\Wallet;

use Illuminate\Support\Carbon;

/**
 * Check a Telegram Mini App's `initData` and say whose it is.
 *
 * Uses Telegram's third-party validation: the launch data is signed with
 * Ed25519 under Telegram's public key over `"{bot_id}:WebAppData\n"` followed
 * by every field except `hash` and `signature`, sorted, as `key=value` lines.
 * No bot token is needed, so Laravel never holds one.
 */
class VerifyTelegramWebAppData
{
    /** How old a launch may be — a Mini App session, not a stored credential. */
    private const MAX_AGE_SECONDS = 86400;

    /**
     * @return array{id: string, username: ?string, first_name: ?string}|null
     */
    public function handle(string $initData): ?array
    {
        parse_str($initData, $fields);

        $signature = $fields['signature'] ?? null;
        $user = isset($fields['user']) ? json_decode((string) $fields['user'], true) : null;

        if (! is_string($signature) || ! is_array($user) || ! isset($user['id'])) {
            return null;
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);

        if ($authDate === 0 || Carbon::now()->timestamp - $authDate > self::MAX_AGE_SECONDS) {
            return null;
        }

        unset($fields['hash'], $fields['signature']);
        ksort($fields);

        $lines = [];

        foreach ($fields as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        $message = config('services.telegram_bot.id').":WebAppData\n".implode("\n", $lines);
        $publicKey = hex2bin((string) config('services.telegram_bot.webapp_public_key'));
        $raw = base64_decode(strtr($signature, '-_', '+/'), true);

        if ($publicKey === false || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        if (! sodium_crypto_sign_verify_detached($raw, $message, $publicKey)) {
            return null;
        }

        return [
            'id' => (string) $user['id'],
            'username' => isset($user['username']) ? (string) $user['username'] : null,
            'first_name' => isset($user['first_name']) ? (string) $user['first_name'] : null,
        ];
    }
}
