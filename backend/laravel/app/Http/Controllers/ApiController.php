<?php

namespace App\Http\Controllers;

use App\Services\CataasApiService;
use App\Services\CyberPriceService;
use App\Services\YesNoApiService;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Laravel\Fortify\Features;

class ApiController extends Controller
{
    public function index(YesNoApiService $yesNo, CataasApiService $cataas, CyberPriceService $cyber)
    {
        $cats = $cataas->cats();
        $yesOrNo = $yesNo->get();
        $tags = $cataas->tags();
        $price = $cyber->get();

        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()),
            'yesOrNo' => $yesOrNo,
            'price' => $price,
            'cataas' => [
                'cat' => $cataas->cat(),
                'catSays' => $cataas->catSays($yesOrNo->answer),
                'catById' => $cataas->catById(Arr::random($cats)->id),
                'catByIdSays' => $cataas->catByIdSays(Arr::random($cats)->id, $yesOrNo->answer),
                'catByTag' => $cataas->catByTag(Arr::random($tags)),
                'catByTagSays' => $cataas->catByTagSays(Arr::random($tags), $yesOrNo->answer),
                'cats' => $cats,
                'count' => $cataas->count(),
                'tags' => $tags,
            ],
        ]);
    }
}
