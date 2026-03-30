<?php
namespace App\Http\Controllers;

use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Services\YesNoApiService;
use App\Services\CataasApiService;
use Illuminate\Support\Arr;

class ApiController extends Controller 
{

    public function index(YesNoApiService $yesNo, CataasApiService $cataas)
    {
        $cats = $cataas->cats();
        $yesOrNo = $yesNo->get();
        $tags = $cataas->tags();

        return Inertia::render("Welcome", [
            'canRegister' => Features::enabled(Features::registration()),
            'yesOrNo' => $yesOrNo,
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
            ]
        ]);
    }
}