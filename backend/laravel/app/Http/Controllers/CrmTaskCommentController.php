<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrmTaskCommentRequest;
use App\Models\CrmTask;
use App\Services\Console\ConsoleFeed;
use Illuminate\Http\RedirectResponse;

class CrmTaskCommentController extends Controller
{
    public function store(StoreCrmTaskCommentRequest $request, CrmTask $task): RedirectResponse
    {
        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        ConsoleFeed::forget();

        return back()->with('success', 'Comment added');
    }
}
