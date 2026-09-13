<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrmTaskAttachment extends Model
{
    protected $fillable = ['user_id', 'path', 'name', 'mime', 'size', 'kind', 'caption'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (CrmTaskAttachment $attachment) => Storage::disk('local')->delete($attachment->path));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class, 'crm_task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CrmTaskAttachmentComment::class)->oldest();
    }

    public static function kindFor(string $name): string
    {
        $extension = Str::lower(Str::afterLast($name, '.'));

        return match (true) {
            in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'], true) => 'image',
            in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], true) => 'video',
            in_array($extension, ['mp3', 'wav', 'ogg', 'm4a'], true) => 'audio',
            default => 'file',
        };
    }
}
