<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrmTaskRequest;
use App\Http\Requests\UpdateCrmTaskRequest;
use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmTaskController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->string('q')->value() ?: null,
            'status' => $request->string('status')->value() ?: null,
            'assignee' => $request->string('assignee')->value() ?: null,
            'priority' => $request->string('priority')->value() ?: null,
            // "1" narrows to active tasks past their due date.
            'overdue' => $request->boolean('overdue') ?: null,
        ];

        $tasks = CrmTask::query()
            ->when($filters['q'], fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'], fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['overdue'], fn ($q) => $q->overdue())
            ->assignee($filters['assignee'], $request->user()?->id)
            ->with(['assignee:id,name', 'contact:id,name,email'])
            ->byDueDate()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('crm/Tasks', [
            'tasks' => $tasks,
            'filters' => $filters,
            'stats' => $this->stats($request->user()?->id),
            'options' => [
                'statuses' => CrmTask::STATUSES,
                'priorities' => CrmTask::PRIORITIES,
                'assignees' => $this->assignees(),
            ],
        ]);
    }

    /**
     * Create a task. Reached both standalone (POST /crm/tasks) and nested
     * under a contact (POST /crm/{contact}/tasks), which binds $contact.
     */
    public function store(StoreCrmTaskRequest $request, ?CrmContact $contact = null): RedirectResponse
    {
        CrmTask::create([
            ...$request->validated(),
            'crm_contact_id' => $contact?->id,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return back()->with('success', 'Task created');
    }

    public function update(UpdateCrmTaskRequest $request, CrmTask $task): RedirectResponse
    {
        $task->update($request->validated());

        return back()->with('success', 'Task updated');
    }

    public function destroy(CrmTask $task): RedirectResponse
    {
        $task->delete();

        return back()->with('success', 'Task deleted');
    }

    /**
     * Operators a task can be assigned to — the CRM allow list, resolved to
     * real accounts. A wallet that never logged in has no row and is absent.
     *
     * @return array<int, array{id: int, name: string, wallet_address: string|null}>
     */
    private function assignees(): array
    {
        return User::crmOperators()
            ->get(['id', 'name', 'wallet_address'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'wallet_address' => $user->wallet_address,
            ])
            ->all();
    }

    /**
     * Counters for the cards above the list.
     *
     * @return array<string, int>
     */
    private function stats(?int $currentUserId): array
    {
        return [
            'open' => CrmTask::where('status', 'open')->count(),
            'in_progress' => CrmTask::where('status', 'in_progress')->count(),
            'overdue' => CrmTask::query()->overdue()->count(),
            'unassigned' => CrmTask::query()->active()->whereNull('assigned_to_user_id')->count(),
            'mine' => $currentUserId
                ? CrmTask::query()->active()->where('assigned_to_user_id', $currentUserId)->count()
                : 0,
            'done' => CrmTask::where('status', 'done')->count(),
        ];
    }
}
