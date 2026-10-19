<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message, waiting for the address it is addressed to.
 *
 * Two kinds share the queue. A **sealed** row is unreadable here: `body` is
 * AES-GCM ciphertext whose key was never on this server and never will be. A
 * **plain** row (`sealed = false`) is a message its sender chose not to
 * encrypt — usually because the recipient has no messaging key yet — so its
 * `body` is the text itself and `iv` is null; the wallet marks every such
 * message as readable by Cyberia. Either way, who sent it, to whom and when is
 * the metadata the relay needs to deliver it, and the wallet says plainly that
 * this part is not private.
 *
 * The row is also not the record of the conversation. It is a queue entry, and
 * `wallet:chat-prune` deletes it once it is old enough; the wallets at either
 * end keep their own copies.
 *
 * @property string $message_id
 * @property string $from_address
 * @property string $to_address
 * @property bool $sealed
 * @property string $sent_at
 * @property string|null $iv
 * @property string $body
 */
class WalletChatMessage extends Model
{
    /** Written once, never updated — there is no `updated_at` to maintain. */
    public const UPDATED_AT = null;

    /**
     * Sealed unless created otherwise, in memory as in the table: a fresh row
     * read before it is reloaded must not look like a plain one.
     */
    protected $attributes = [
        'sealed' => true,
    ];

    protected $fillable = [
        'message_id',
        'from_address',
        'to_address',
        'sealed',
        'sent_at',
        'iv',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'sealed' => 'boolean',
        ];
    }
}
