<?php

namespace App\Models;

use Database\Factories\ArenaRoundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArenaRound extends Model
{
    /** @use HasFactory<ArenaRoundFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decision_at' => 'immutable_datetime', 'filled_at' => 'immutable_datetime', 'evidence' => 'array', 'result' => 'array'];
    }
}
