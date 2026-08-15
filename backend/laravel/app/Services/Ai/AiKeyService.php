<?php

namespace App\Services\Ai;

use App\Exceptions\AiApiException;
use App\Models\AiApiKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Issuing, finding and revoking the API's keys.
 *
 * A key is a bearer secret with no structure worth parsing: a recognisable
 * prefix so a leaked one can be spotted in a log or a repository, and enough
 * randomness after it that guessing is not a strategy. It is stored as a
 * SHA-256 — fast on purpose, because the input is 48 characters of entropy
 * rather than a password, and a per-request KDF would only tax the API.
 *
 * Nothing here checks the balance behind an address; that is AiHolderGate's
 * job, and it runs on every call rather than once at issuance.
 */
class AiKeyService
{
    /** Recognisable, and unlike any provider's own prefix. */
    public const PREFIX = 'sk-cyb-';

    private const SECRET_CHARS = 48;

    /** How much of the key a listing may show. */
    private const VISIBLE_CHARS = 14;

    /**
     * A new key for $address. The plaintext is returned once and never again.
     *
     * @return array{key: AiApiKey, token: string}
     */
    public function issue(string $address, ?string $name = null, bool $gateExempt = false): array
    {
        $address = Str::lower($address);
        $limit = (int) config('ai.limits.keys_per_address', 5);

        // Service keys are issued by an operator at the console, one at a
        // time, and are not what the per-address limit is defending against.
        if (! $gateExempt && $limit > 0 && $this->active($address)->count() >= $limit) {
            throw AiApiException::invalidRequest(
                "This address already holds {$limit} active keys. Revoke one before issuing another.",
                'address',
                'key_limit_reached',
            );
        }

        $token = self::PREFIX.Str::random(self::SECRET_CHARS);

        $key = AiApiKey::create([
            'address' => $address,
            'name' => $this->cleanName($name),
            'prefix' => substr($token, 0, self::VISIBLE_CHARS),
            'token_hash' => $this->hash($token),
            'gate_exempt' => $gateExempt,
        ]);

        return ['key' => $key, 'token' => $token];
    }

    /** The key a bearer token names, if it exists and still works. */
    public function resolve(string $token): ?AiApiKey
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $key = AiApiKey::where('token_hash', $this->hash($token))->first();

        return $key === null || $key->revoked() ? null : $key;
    }

    /** @return Collection<int, AiApiKey> */
    public function active(string $address): Collection
    {
        return AiApiKey::where('address', Str::lower($address))
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, AiApiKey> */
    public function forAddress(string $address): Collection
    {
        return AiApiKey::where('address', Str::lower($address))
            ->orderByDesc('id')
            ->get();
    }

    public function revoke(AiApiKey $key): AiApiKey
    {
        if (! $key->revoked()) {
            $key->forceFill(['revoked_at' => Carbon::now()])->save();
        }

        return $key;
    }

    /**
     * Note that a key was used.
     *
     * Written at most once a minute per key: `last_used_at` answers "is this
     * key still in use", a question no finer resolution improves, and an API
     * under load should not spend a write per request on it.
     */
    public function touch(AiApiKey $key): void
    {
        if ($key->last_used_at !== null && $key->last_used_at->gt(Carbon::now()->subMinute())) {
            return;
        }

        $key->forceFill(['last_used_at' => Carbon::now()])->save();
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : Str::limit($name, 57);
    }
}
