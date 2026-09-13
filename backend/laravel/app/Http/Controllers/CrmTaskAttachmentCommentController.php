<?php

namespace App\Http\Controllers;

use App\Models\CrmTaskAttachment;
use App\Models\CrmTaskAttachmentComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CrmTaskAttachmentCommentController extends Controller
{
    public function store(Request $request, CrmTaskAttachment $attachment): RedirectResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $attachment->comments()->create(['user_id' => $request->user()?->id, 'body' => trim($validated['body'])]);

        return back()->with('success', 'Comment added');
    }

    public function destroy(Request $request, CrmTaskAttachmentComment $comment): RedirectResponse
    {
        abort_unless($comment->user_id === $request->user()?->id, 404);
        $comment->delete();

        return back()->with('success', 'Comment deleted');
    }
}
