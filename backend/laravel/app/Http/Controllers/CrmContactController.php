<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrmContactRequest;
use App\Http\Requests\UpdateCrmContactRequest;
use App\Models\CrmContact;
use App\Models\CrmIdentityLink;
use App\Models\CrmTask;
use App\Models\User;
use App\Services\Console\IdentityGraph;
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
        private IdentityGraph $identities,
    ) {}

    /**
     * Say that this record and another identity are one person.
     *
     * The console can already see the evidence — a bridge request naming an
     * account and the address that signed its deposit — but evidence is not
     * every case: an operator recognising a customer from a conversation is
     * the case no derivation will ever cover, and it is why this is a button
     * rather than only a command.
     *
     * The target is given as `kind:value` (`user:38`, `evm:0x…`, `solana:…`)
     * or as a bare address, whose shape decides the kind. Linking to a contact
     * record is deliberately not offered: a record is not an identity, and the
     * link has to survive the sync rebuilding it.
     */
    public function link(Request $request, CrmContact $contact): RedirectResponse
    {
        $target = trim((string) $request->string('target'));
        $parsed = $this->parseIdentity($target);

        if ($parsed === null) {
            return back()->withErrors(['target' => 'Not an account id, an EVM address or a Solana address.']);
        }

        $mine = IdentityGraph::nodesOf($contact);

        if ($mine === []) {
            return back()->withErrors(['target' => 'This record carries no identity to link from.']);
        }

        // Anchored on this record's strongest identity: its account if it has
        // one, otherwise the first address it carries. Linking every node
        // would assert edges nobody claimed.
        [$kind, $value] = explode(':', $mine[0], 2);

        $this->identities->link(
            $kind,
            $value,
            $parsed[0],
            $parsed[1],
            'manual',
            'operator',
            'strong',
            $request->user()?->id,
        );

        return back();
    }

    /**
     * Promote a suggestion, or withdraw a link entirely.
     *
     * Both directions exist because a judgement that cannot be taken back is a
     * judgement people stop making. Nothing is deleted but the claim itself —
     * the records at either end are untouched.
     */
    public function unlink(CrmIdentityLink $link): RedirectResponse
    {
        $link->delete();
        $this->identities->forget();

        return back();
    }

    public function confirmLink(CrmIdentityLink $link): RedirectResponse
    {
        $link->forceFill(['confidence' => 'strong', 'source' => 'manual'])->save();
        $this->identities->forget();

        return back();
    }

    /**
     * Read an identity out of what an operator typed.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseIdentity(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        if (str_contains($value, ':')) {
            [$kind, $rest] = explode(':', $value, 2);
            $kind = strtolower(trim($kind));

            if (in_array($kind, ['user', 'evm', 'solana'], true) && trim($rest) !== '') {
                return [$kind, trim($rest)];
            }

            return null;
        }

        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $value) === 1) {
            return ['evm', $value];
        }

        if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $value) === 1) {
            return ['solana', $value];
        }

        if (ctype_digit($value)) {
            return ['user', $value];
        }

        return null;
    }

    public function index(Request $request): Response
    {
        $segment = PeopleLens::has((string) $request->query('segment'))
            ? (string) $request->query('segment')
            : 'all';

        $search = $request->string('q')->value() ?: null;

        /*
         * The narrowing an operator does inside a segment, and the order they
         * read it in. Both are in the address, so a question worth asking
         * twice can be kept in a bookmark or pasted to the other desk — which
         * is the same reason a segment carries its rule on screen.
         */
        $type = in_array($request->query('type'), CrmContact::TYPES, true)
            ? (string) $request->query('type')
            : null;

        $status = in_array($request->query('status'), CrmContact::STATUSES, true)
            ? (string) $request->query('status')
            : null;

        $sort = in_array($request->query('sort'), PeopleLens::SORTS, true)
            ? (string) $request->query('sort')
            : 'signal';

        return Inertia::render('crm/People', [
            'segment' => $segment,
            'segments' => $this->lens->segments(),
            'search' => $search,
            'type' => $type,
            'status' => $status,
            'sort' => $sort,
            // How old what you are reading is, beside the button that
            // refreshes it — a list with no date is a list nobody can act on.
            'sync' => $this->lens->lastSync(),
            ...$this->lens->rows(
                $segment,
                $search,
                (int) $request->integer('rows', 40),
                $type,
                $status,
                $sort,
            ),
            'options' => [
                'types' => CrmContact::TYPES,
                'statuses' => CrmContact::STATUSES,
                'sorts' => PeopleLens::SORTS,
            ],
        ]);
    }

    /**
     * One person's dossier.
     *
     * Which slice of the stream is being read, and how much of it, are in the
     * address for the same reason the lens keeps its filters there: a dossier
     * scrolled down to the money is a thing worth pasting to the other desk,
     * and the back button should undo a filter rather than leave the page.
     */
    public function show(Request $request, CrmContact $contact): Response
    {
        return Inertia::render('crm/Person', $this->dossier->build(
            $contact,
            (string) $request->query('events', 'all'),
            (int) $request->integer('rows', 60),
        ) + [
            'options' => [
                'types' => CrmContact::TYPES,
                'statuses' => CrmContact::STATUSES,
                'taskPriorities' => CrmTask::PRIORITIES,
                'taskStatuses' => CrmTask::STATUSES,
                'views' => PersonDossier::VIEWS,
                'assignees' => User::crmOperators()
                    ->get(['id', 'name'])
                    ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                    ->all(),
            ],
        ]);
    }

    /**
     * Put a person on the books by hand.
     *
     * The redirect goes back to the lens rather than into the new dossier,
     * because contacts arrive in handfuls — fifteen accounts found in one
     * afternoon — and a form that closes itself after each one turns a list
     * into fifteen round trips. The new row is on the screen already — a
     * contact created a second ago has the freshest signal there is, so it
     * sorts to the top of the lens — and the dossier is one click from it.
     */
    public function store(StoreCrmContactRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['source'] = 'manual';

        $contact = CrmContact::create($data);

        return back()->with('success', 'Contact created');
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
