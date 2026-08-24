<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrmMessageRequest;
use App\Models\CrmContact;
use App\Models\CrmMessage;
use App\Services\Console\ConsoleFeed;
use Illuminate\Http\RedirectResponse;

/**
 * The correspondence with one person.
 *
 * Written by hand today and imported from Telegram and Discord later, which
 * is why nothing here is bound to a transport: a line is a direction, a
 * channel, a body and the moment it was said. An importer will create the
 * same rows with `external_id` filled in, and the unique index on
 * (channel, external_id) is what makes replaying an export safe.
 *
 * There is no send button and there will not be one from here: this console
 * holds no Telegram session for an operator, and a CRM that pretends to
 * deliver a message it never sent is worse than one that only records.
 */
class CrmMessageController extends Controller
{
    public function store(StoreCrmMessageRequest $request, CrmContact $contact): RedirectResponse
    {
        $data = $request->validated();

        $contact->messages()->create([
            // Who wrote the line *down*. An inbound line was said by the
            // contact, so the operator is a scribe there and not its author —
            // but the row still records which desk entered it.
            'user_id' => $request->user()?->id,
            'direction' => $data['direction'],
            'channel' => $data['channel'],
            'body' => $data['body'],
            'sent_at' => $data['sent_at'] ?? now(),
        ]);

        // The queue counts a whale who has been waiting on us, so a line
        // written here can change what "Сейчас" says.
        ConsoleFeed::forget();

        return back()->with('success', 'Message recorded');
    }

    public function destroy(CrmMessage $message): RedirectResponse
    {
        $message->delete();

        ConsoleFeed::forget();

        return back()->with('success', 'Message deleted');
    }
}
