<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrmNoteRequest;
use App\Models\CrmContact;
use App\Models\CrmNote;
use Illuminate\Http\RedirectResponse;

class CrmNoteController extends Controller
{
    public function store(StoreCrmNoteRequest $request, CrmContact $contact): RedirectResponse
    {
        $contact->notes()->create([
            'user_id' => $request->user()?->id,
            'type' => 'note',
            'body' => $request->validated()['body'],
        ]);

        return back()->with('success', 'Note added');
    }

    public function destroy(CrmNote $note): RedirectResponse
    {
        $note->delete();

        return back()->with('success', 'Note deleted');
    }
}
