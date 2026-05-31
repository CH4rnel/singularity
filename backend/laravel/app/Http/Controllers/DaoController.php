<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDaoRequest;
use App\Http\Requests\UpdateDaoRequest;
use App\Models\Dao;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DaoController extends Controller
{
    public function index()
    {
        return Inertia::render('dao/Index', [
            'daos' => Dao::withCount('proposals')->get(),
        ]);
    }

    public function show(Dao $dao)
    {
        return Inertia::render('dao/Show', [
            'dao' => $dao,
            'proposals' => $dao->proposals()
                ->with(['user', 'votes'])
                ->withCount(['comments'])
                ->withSum('votesFor as power_for', 'voting_power')
                ->withSum('votesAgainst as power_against', 'voting_power')
                ->latest()
                ->get(),
        ]);
    }

    public function store(StoreDaoRequest $request): RedirectResponse
    {
        Dao::create($request->validated());

        return back()->with('success', 'DAO created');
    }

    public function update(UpdateDaoRequest $request, Dao $dao): RedirectResponse
    {
        $dao->update($request->validated());

        return back()->with('success', 'DAO updated');
    }

    public function destroy(Dao $dao): RedirectResponse
    {
        $dao->delete();

        return back()->with('success', 'DAO deleted');
    }
}
