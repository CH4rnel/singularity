<?php

namespace App\Models;

use Database\Factories\ArenaFollowerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArenaFollower extends Model
{
    /** @use HasFactory<ArenaFollowerFactory> */
    use HasFactory;

    protected $fillable = ['user_id'];
}
