<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmOperatorProfile extends Model
{
    public const THEMES = ['cyberia', 'burichan', 'yotsuba', 'photon', 'wakaba'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['contacts' => 'array', 'registered_at' => 'datetime'];
    }
}
