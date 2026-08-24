<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A file that came into the room attached to a message.
 *
 * @property int $id
 * @property int $crm_chat_message_id
 * @property int|null $user_id
 * @property string $path
 * @property string $name
 * @property string|null $mime
 * @property int $size
 * @property string $kind
 * @property Carbon $created_at
 */
class CrmChatFile extends Model
{
    /**
     * The segments of the files lens.
     *
     * A segment is a saved question, so the answer is decided once — on
     * upload — rather than re-guessed by every query that lists files.
     */
    public const KINDS = ['image', 'log', 'doc', 'archive', 'other'];

    /**
     * The largest file this room will open at all, in bytes.
     *
     * Only the head of it is ever quoted (`crm.chat.lainos.file_bytes`); this
     * is the separate question of what is worth reading off the disk in the
     * first place.
     */
    public const READABLE_MAX_BYTES = 2 * 1024 * 1024;

    protected $fillable = [
        'crm_chat_message_id',
        'user_id',
        'path',
        'name',
        'mime',
        'size',
        'kind',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CrmChatMessage::class, 'crm_chat_message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The extension, uppercased, as the room and the table print it. */
    public function extension(): string
    {
        $extension = Str::afterLast($this->name, '.');

        return $extension === $this->name || $extension === ''
            ? '—'
            : Str::upper(Str::limit($extension, 5, ''));
    }

    /**
     * Which segment a file belongs to, from its extension.
     *
     * Extension and not mime type: a browser reports `application/octet-stream`
     * for half of what an operator drags in, and the name is what the person
     * who dropped it was looking at.
     */
    public static function kindFor(string $name): string
    {
        $extension = Str::lower(Str::afterLast($name, '.'));

        return match (true) {
            in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp', 'svg'], true) => 'image',
            in_array($extension, ['log', 'txt', 'json', 'csv', 'ndjson', 'yml', 'yaml', 'sql'], true) => 'log',
            in_array($extension, ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'md'], true) => 'doc',
            in_array($extension, ['zip', 'gz', 'tgz', 'tar', 'bz2', 'xz', '7z', 'rar'], true) => 'archive',
            default => 'other',
        };
    }

    /**
     * Whether this file can be handed to LainOS as text.
     *
     * Only what is plainly text: a log, a dump, a config. Everything else goes
     * up as a name and a size, which is what the room says it does.
     */
    public function isReadableText(): bool
    {
        return $this->kind === 'log' && $this->size <= self::READABLE_MAX_BYTES;
    }
}
