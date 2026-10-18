<?php

namespace App\Models;

use Database\Factories\ArenaCycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArenaCycle extends Model
{
    /** @use HasFactory<ArenaCycleFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decision_at' => 'immutable_datetime', 'fill_due_at' => 'immutable_datetime', 'record' => 'array'];
    }
}
