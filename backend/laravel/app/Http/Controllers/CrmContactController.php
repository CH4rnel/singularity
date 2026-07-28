<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrmContactRequest;
use App\Http\Requests\UpdateCrmContactRequest;
use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmContactController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->string('q')->value() ?: null,
            'type' => $request->string('type')->value() ?: null,
            'status' => $request->string('status')->value() ?: null,
            'source' => $request->string('source')->value() ?: null,
            'chain' => $request->string('chain')->value() ?: null,
        ];

        $contacts = CrmContact::query()
            ->search($filters['q'])
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['source'], fn ($q, $source) => $q->where('source', $source))
            ->chain($filters['chain'])
            ->withCount('notes')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('crm/Index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'stats' => $this->stats(),
            'options' => [
                'types' => CrmContact::TYPES,
                'statuses' => CrmContact::STATUSES,
                'sources' => CrmContact::SOURCES,
                'chains' => CrmContact::CHAINS,
            ],
        ]);
    }

    public function show(CrmContact $contact): Response
    {
        $contact->load(['notes.author', 'user', 'tasks.assignee:id,name']);

        $addresses = array_filter([$contact->evm_address, $contact->solana_address]);

        $bridgeActivity = $addresses === []
            ? collect()
            : BridgeRequest::query()
                ->whereIn('sender_address', $addresses)
                ->orWhereIn('recipient_address', $addresses)
                ->latest()
                ->limit(20)
                ->get(['id', 'direction', 'token', 'amount', 'status', 'created_at']);

        return Inertia::render('crm/Show', [
            'contact' => $contact,
            'bridgeActivity' => $bridgeActivity,
            'options' => [
                'types' => CrmContact::TYPES,
                'statuses' => CrmContact::STATUSES,
                'taskStatuses' => CrmTask::STATUSES,
                'taskPriorities' => CrmTask::PRIORITIES,
                'assignees' => User::crmOperators()->get(['id', 'name'])->all(),
            ],
        ]);
    }

    public function store(StoreCrmContactRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['source'] = 'manual';

        CrmContact::create($data);

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

        return to_route('crm.index')->with('success', 'Contact deleted');
    }

    /**
     * Aggregate counts for the dashboard cards.
     *
     * @return array<string, int|float>
     */
    private function stats(): array
    {
        return [
            'total' => CrmContact::count(),
            'leads' => CrmContact::where('type', 'lead')->count(),
            'holders' => CrmContact::where('type', 'holder')->count(),
            'whales' => CrmContact::where('type', 'whale')->count(),
            'customers' => CrmContact::where('status', 'customer')->count(),
            'evm' => CrmContact::query()->chain('evm')->count(),
            'solana' => CrmContact::query()->chain('solana')->count(),
            'both' => CrmContact::query()->chain('both')->count(),
        ];
    }
}
