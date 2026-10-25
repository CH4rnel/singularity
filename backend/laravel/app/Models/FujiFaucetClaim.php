<?php

namespace App\Models;

use Database\Factories\FujiFaucetClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One drip of test CYBER on Cyberia Fuji, as this server sent it.
 *
 * The chain is the authority on the money; this row is what the cooldown and
 * the daily quotas are counted from.
 */
class FujiFaucetClaim extends Model
{
    /** @use HasFactory<FujiFaucetClaimFactory> */
    use HasFactory;

    protected $fillable = [
        'address',
        'amount_wei',
        'tx_hash',
        'ip_hash',
    ];
}
