<?php

namespace App\Models;

use Database\Factories\CrmNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $crm_contact_id
 * @property int|null $user_id
 * @property string $type
 * @property string $body
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CrmNote extends Model
{
    /** @use HasFactory<CrmNoteFactory> */
    use HasFactory;

    protected $fillable = [
        'crm_contact_id',
        'user_id',
        'type',
        'body',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
