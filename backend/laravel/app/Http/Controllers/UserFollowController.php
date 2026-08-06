<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserFollowController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_if($request->user()->is($user), 422, 'You cannot follow yourself.');

        $request->user()->following()->syncWithoutDetaching([$user->id]);

        return back()->with('status', 'user-followed');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $request->user()->following()->detach($user);

        return back()->with('status', 'user-unfollowed');
    }
}
