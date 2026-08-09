<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One address's published messaging key.
 *
 * Public by necessity: encrypting to someone requires their key, and an EVM
 * address is a hash — it cannot be turned back into one. The signature stored
 * alongside is what keeps this directory honest, since every reader verifies
 * that the record recovers to the address it claims before using it.
 *
 * `issued_at` is stored as the string it was signed as, not as a date: the
 * signature covers those exact characters.
 *
 * @property string $address
 * @property string $public_key
 * @property string $issued_at
 * @property string $signature
 */
class WalletChatKey extends Model
{
    protected $fillable = [
        'address',
        'public_key',
        'issued_at',
        'signature',
    ];
}
