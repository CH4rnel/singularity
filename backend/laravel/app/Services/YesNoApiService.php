<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class YesNoApiService
{
    public function get()
    {
        return Http::get('https://yesno.wtf/api')->object();
    }
}
