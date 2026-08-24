<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One assertion that two identities are the same person.
 *
 * Undirected: the pair is stored in a canonical order so the same claim made
 * from either end is one row. Nothing here is destructive — the records at
 * both ends keep existing, and deleting this row withdraws the claim.
 */
class CrmIdentityLink extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Normalise one endpoint.
     *
     * EVM addresses are lowercased because the same key is written with and
     * without checksum casing all over this codebase — `crm_contacts` stores
     * one form and `bridge_requests` the other, and an edge that did not
     * normalise would join nothing. Solana keys are base58 and case is
     * meaningful, so they are left exactly as they are.
     */
    public static function normalize(string $kind, string $value): string
    {
        $value = trim($value);

        return $kind === 'evm' ? strtolower($value) : $value;
    }

    /**
     * The two endpoints in the order they are stored.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function order(string $aKind, string $aValue, string $bKind, string $bValue): array
    {
        $a = [$aKind, self::normalize($aKind, $aValue)];
        $b = [$bKind, self::normalize($bKind, $bValue)];

        return "{$a[0]}:{$a[1]}" <= "{$b[0]}:{$b[1]}"
            ? [$a[0], $a[1], $b[0], $b[1]]
            : [$b[0], $b[1], $a[0], $a[1]];
    }
}
