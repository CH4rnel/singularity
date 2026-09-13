<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmTaskAttachmentComment extends Model
{
    protected $fillable = ['user_id', 'body'];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(CrmTaskAttachment::class, 'crm_task_attachment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
