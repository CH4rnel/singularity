<?php

namespace App\Models;

use Database\Factories\CrmTaskCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $crm_task_id
 * @property int $user_id
 * @property string $body
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CrmTaskComment extends Model
{
    /** @use HasFactory<CrmTaskCommentFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'body'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class, 'crm_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
