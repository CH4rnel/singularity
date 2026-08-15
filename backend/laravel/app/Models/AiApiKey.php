<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One credential for the inference API, held by an address.
 *
 * The secret is absent by construction: only its hash is stored, so the key
 * exists in full exactly once — in the response that issued it. A holder who
 * loses it revokes and issues another.
 *
 * Revocation is a timestamp rather than a delete, so the request log it owns
 * survives it and usage stays attributable.
 *
 * @property string $address
 * @property ?string $name
 * @property string $prefix
 * @property string $token_hash
 * @property ?Carbon $last_used_at
 * @property ?Carbon $revoked_at
 */
class AiApiKey extends Model
{
    protected $fillable = [
        'address',
        'name',
        'prefix',
        'token_hash',
        'gate_exempt',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'gate_exempt' => 'boolean',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return HasMany<AiApiRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(AiApiRequest::class);
    }

    public function revoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** What a holder sees about their own key. Never the key. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'gate_exempt' => (bool) $this->gate_exempt,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
