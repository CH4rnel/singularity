<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrmContactRequest;
use App\Http\Requests\UpdateCrmContactRequest;
use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\User;
use App\Services\Console\PeopleLens;
use App\Services\Console\PersonDossier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Люди" and one person's dossier.
 *
 * The list is a lens rather than a table: a segment is a saved question with
 * its rule on screen, and the middle of every row says what happened to that
 * person rather than repeating their database columns.
 */
class CrmContactController extends Controller
{
    public function __construct(
        private PeopleLens $lens,
        private PersonDossier $dossier,
    ) {}

    public function index(Request $request): Response
    {
        $segment = PeopleLens::has((string) $request->query('segment'))
            ? (string) $request->query('segment')
            : 'all';

        $search = $request->string('q')->value() ?: null;

        return Inertia::render('crm/People', [
            'segment' => $segment,
            'segments' => $this->lens->segments(),
            'search' => $search,
            ...$this->lens->rows($segment, $search, (int) $request->integer('rows', 40)),
            'options' => [
                'types' => CrmContact::TYPES,
                'statuses' => CrmContact::STATUSES,
            ],
        ]);
    }

    public function show(CrmContact $contact): Response
    {
        return Inertia::render('crm/Person', $this->dossier->build($contact) + [
            'options' => [
                'types' => CrmContact::TYPES,
                'statuses' => CrmContact::STATUSES,
                'taskPriorities' => CrmTask::PRIORITIES,
                'assignees' => User::crmOperators()
                    ->get(['id', 'name'])
                    ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                    ->all(),
            ],
        ]);
    }

    public function store(StoreCrmContactRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['source'] = 'manual';

        $contact = CrmContact::create($data);

        return to_route('crm.show', $contact)->with('success', 'Contact created');
    }

    public function update(UpdateCrmContactRequest $request, CrmContact $contact): RedirectResponse
    {
        $contact->update($request->validated());

        return back()->with('success', 'Contact updated');
    }

    public function destroy(CrmContact $contact): RedirectResponse
    {
        $contact->delete();

        return to_route('crm.people')->with('success', 'Contact deleted');
    }
}
