<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sealed message, waiting for the address it is addressed to.
 *
 * Everything meaningful in this row is unreadable here: `body` is AES-GCM
 * ciphertext whose key was never on this server and never will be. What is
 * legible — who sent it, to whom, and when — is the metadata the relay needs
 * to deliver it, and the wallet says plainly that this part is not private.
 *
 * The row is also not the record of the conversation. It is a queue entry, and
 * `wallet:chat-prune` deletes it once it is old enough; the wallets at either
 * end keep their own copies.
 *
 * @property string $message_id
 * @property string $from_address
 * @property string $to_address
 * @property string $sent_at
 * @property string $iv
 * @property string $body
 */
class WalletChatMessage extends Model
{
    /** Written once, never updated — there is no `updated_at` to maintain. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'from_address',
        'to_address',
        'sent_at',
        'iv',
        'body',
    ];
}
