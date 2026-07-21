<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaunchpadToken extends Model
{
    protected $primaryKey = 'address';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'address',
        'creator',
        'name',
        'symbol',
        'description',
        'image_path',
        'html_path',
        'site_subdomain',
    ];
}
