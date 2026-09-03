<?php

namespace App\Support;

/**
 * Whether web push can actually send, and if not, which half is wrong.
 *
 * This exists because of a failure that looked like nothing at all: the keys
 * were "set", the subscription was stored, the browser reported success, and
 * every send died inside the library with `Invalid data provided`. The private
 * key had been written in a form the signer cannot use — 57 bytes of standard
 * base64 where a raw 32-byte scalar was wanted — and nothing anywhere said so.
 *
 * So the shape is checked rather than the presence. A key that is set and
 * unusable is worse than one that is missing, because the missing one at least
 * turns the button off.
 */
class VapidHealth
{
    /** An uncompressed P-256 point: 0x04 followed by two 32-byte coordinates. */
    private const PUBLIC_BYTES = 65;

    /** The private scalar, raw. */
    private const PRIVATE_BYTES = 32;

    /**
     * @return array{ok: bool, public: string, private: string, subject: string, problems: array<int, string>}
     */
    public static function check(): array
    {
        $public = (string) config('webpush.vapid.public_key');
        $private = (string) config('webpush.vapid.private_key');
        $subject = (string) config('webpush.vapid.subject');

        $problems = [];

        $publicState = self::state($public, self::PUBLIC_BYTES);
        $privateState = self::state($private, self::PRIVATE_BYTES);

        if ($publicState !== 'ok') {
            $problems[] = "VAPID_PUBLIC_KEY {$publicState} — браузер не примет подписку.";
        }

        if ($privateState !== 'ok') {
            $problems[] = "VAPID_PRIVATE_KEY {$privateState} — отправка упадёт внутри библиотеки.";
        }

        if (trim($subject) === '') {
            $problems[] = 'VAPID_SUBJECT не задан — push-сервисы отклоняют отправку без него.';
        }

        return [
            'ok' => $problems === [],
            'public' => $publicState,
            'private' => $privateState,
            'subject' => trim($subject) === '' ? 'не задан' : $subject,
            'problems' => $problems,
        ];
    }

    /** 'ok', or a sentence naming what is wrong with this value. */
    private static function state(string $value, int $expectedBytes): string
    {
        if (trim($value) === '') {
            return 'не задан';
        }

        if (preg_match('#^[A-Za-z0-9_-]+$#', $value) !== 1) {
            // Standard base64 rather than base64url: the signer decodes with
            // the URL alphabet, so `+`, `/` and `=` come out as the wrong bytes.
            return 'записан обычным base64 вместо base64url';
        }

        $bytes = strlen((string) base64_decode(strtr($value, '-_', '+/'), false));

        return $bytes === $expectedBytes
            ? 'ok'
            : "декодируется в {$bytes} байт вместо {$expectedBytes}";
    }
}
