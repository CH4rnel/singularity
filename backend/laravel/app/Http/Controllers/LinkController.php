<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Models\Category;
use App\Models\Link;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LinkController extends Controller
{
    public function index()
    {
        $query = Link::with('category');

        if (request()->has('category_id') && request('category_id')) {
            $query->where('category_id', request('category_id'));
        }

        return Inertia::render('links/Index', [
            'links' => $query->get(),
            'categories' => Category::all(),
        ]);
    }

    public function store(StoreLinkRequest $request): RedirectResponse
    {
        Link::create($request->validated());

        return back()->with('success', 'Link created');
    }

    public function update(UpdateLinkRequest $request, Link $link): RedirectResponse
    {
        $link->update($request->validated());

        return back()->with('success', 'Link updated');
    }

    public function destroy(Link $link): RedirectResponse
    {
        $link->delete();

        return back()->with('success', 'Link deleted');
    }
}
