<?php

namespace App\Models;

use Database\Factories\CrmContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $telegram
 * @property string|null $evm_address
 * @property string|null $solana_address
 * @property string $type
 * @property string $status
 * @property string $source
 * @property int|null $user_id
 * @property string|null $cyber_balance
 * @property string|null $cyber_sol_balance
 * @property array<int, string>|null $tags
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $last_synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CrmContact extends Model
{
    /** @use HasFactory<CrmContactFactory> */
    use HasFactory, SoftDeletes;

    public const TYPES = ['lead', 'holder', 'whale'];

    public const STATUSES = ['new', 'contacted', 'qualified', 'customer', 'lost'];

    public const SOURCES = ['manual', 'platform', 'bridge', 'whale_bot'];

    protected $fillable = [
        'name',
        'email',
        'telegram',
        'evm_address',
        'solana_address',
        'type',
        'status',
        'source',
        'user_id',
        'cyber_balance',
        'cyber_sol_balance',
        'tags',
        'metadata',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'cyber_balance' => 'decimal:18',
            'cyber_sol_balance' => 'decimal:6',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CrmNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CrmNote::class)->latest();
    }

    public function isWhale(): bool
    {
        return $this->type === 'whale';
    }

    /**
     * Filter by a free-text query across name, email, telegram and addresses.
     *
     * @param  Builder<CrmContact>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (! $term) {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('telegram', 'like', $like)
                ->orWhere('evm_address', 'like', $like)
                ->orWhere('solana_address', 'like', $like);
        });
    }
}
