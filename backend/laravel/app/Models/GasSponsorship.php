<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One drip served by the gas station, as this server recorded it.
 *
 * The chain is the authority on the money; this row is the authority on who
 * asked and why it was allowed.
 */
class GasSponsorship extends Model
{
    protected $fillable = [
        'address',
        'amount_wei',
        'tx_hash',
        'grounds',
        'ip_hash',
    ];
}
