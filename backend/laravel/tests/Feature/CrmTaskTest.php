<?php

use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/** A second allow-listed operator, so assignment has somewhere to go. */
function secondOperator(): User
{
    return User::factory()->create([
        'wallet_address' => config('crm.admin_wallets')[1],
    ]);
}

test('guests and non-operators cannot reach tasks', function () {
    $this->get(route('crm.tasks.index'))->assertRedirect(route('login'));

    $stranger = User::factory()->create(['wallet_address' => '0x'.str_repeat('e', 40)]);
    $task = CrmTask::factory()->create();

    $this->actingAs($stranger)->get(route('crm.tasks.index'))->assertNotFound();
    $this->actingAs($stranger)->post(route('crm.tasks.store'), ['title' => 'x'])->assertNotFound();
    $this->actingAs($stranger)->put(route('crm.tasks.update', $task), ['status' => 'done'])->assertNotFound();
    $this->actingAs($stranger)->delete(route('crm.tasks.destroy', $task))->assertNotFound();

    expect($task->fresh()->status)->toBe('open');
});

test('the task list shows tasks with their contact, assignee and stats', function () {
    $operator = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create(['name' => 'Alice']);

    CrmTask::factory()->assignedTo($operator)->create([
        'crm_contact_id' => $contact->id,
        'title' => 'Call Alice back',
    ]);
    CrmTask::factory()->standalone()->overdue()->create(['title' => 'Rotate relayer key']);
    CrmTask::factory()->standalone()->done()->create();

    $this->actingAs($operator)
        ->get(route('crm.tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Tasks')
            ->has('tasks.data', 3)
            ->where('stats.open', 2)
            ->where('stats.overdue', 1)
            ->where('stats.unassigned', 1)
            ->where('stats.mine', 1)
            ->where('stats.done', 1)
            ->where('options.assignees.0.id', $operator->id)
        );
});

test('a task is created and assigned to an operator', function () {
    $operator = User::factory()->crmAdmin()->create();
    $other = secondOperator();

    $this->actingAs($operator)
        ->post(route('crm.tasks.store'), [
            'title' => 'Draft the whale outreach',
            'description' => 'Top 20 holders',
            'assigned_to_user_id' => $other->id,
            'priority' => 'high',
            'due_at' => '2026-08-01',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $task = CrmTask::sole();

    expect($task->title)->toBe('Draft the whale outreach')
        ->and($task->assigned_to_user_id)->toBe($other->id)
        ->and($task->created_by_user_id)->toBe($operator->id)
        ->and($task->crm_contact_id)->toBeNull()
        ->and($task->priority)->toBe('high')
        ->and($task->status)->toBe('open')
        ->and($task->due_at->toDateString())->toBe('2026-08-01');
});

test('a task created from a contact page is linked to that contact', function () {
    $operator = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create();

    $this->actingAs($operator)
        ->post(route('crm.tasks.storeForContact', $contact), [
            'title' => 'Send bridge instructions',
            'assigned_to_user_id' => $operator->id,
        ])
        ->assertRedirect();

    expect(CrmTask::sole()->crm_contact_id)->toBe($contact->id);
});

test('tasks can be reassigned and unassigned', function () {
    $operator = User::factory()->crmAdmin()->create();
    $other = secondOperator();
    $task = CrmTask::factory()->assignedTo($operator)->create();

    $this->actingAs($operator)
        ->put(route('crm.tasks.update', $task), ['assigned_to_user_id' => $other->id])
        ->assertRedirect();

    expect($task->fresh()->assigned_to_user_id)->toBe($other->id);

    $this->actingAs($operator)
        ->put(route('crm.tasks.update', $task), ['assigned_to_user_id' => null])
        ->assertRedirect();

    expect($task->fresh()->assigned_to_user_id)->toBeNull();
});

test('a user outside the crm allow list cannot be assigned a task', function () {
    $operator = User::factory()->crmAdmin()->create();
    $stranger = User::factory()->create(['wallet_address' => '0x'.str_repeat('d', 40)]);

    $this->actingAs($operator)
        ->post(route('crm.tasks.store'), [
            'title' => 'Nope',
            'assigned_to_user_id' => $stranger->id,
        ])
        ->assertSessionHasErrors('assigned_to_user_id');

    $task = CrmTask::factory()->create();

    $this->actingAs($operator)
        ->put(route('crm.tasks.update', $task), ['assigned_to_user_id' => $stranger->id])
        ->assertSessionHasErrors('assigned_to_user_id');

    expect(CrmTask::count())->toBe(1)
        ->and($task->fresh()->assigned_to_user_id)->toBeNull();
});

test('completing a task stamps completed_at and reopening clears it', function () {
    $operator = User::factory()->crmAdmin()->create();
    $task = CrmTask::factory()->assignedTo($operator)->create();

    $this->actingAs($operator)
        ->put(route('crm.tasks.update', $task), ['status' => 'done'])
        ->assertRedirect();

    expect($task->fresh()->completed_at)->not->toBeNull();

    $this->actingAs($operator)
        ->put(route('crm.tasks.update', $task), ['status' => 'open'])
        ->assertRedirect();

    expect($task->fresh()->completed_at)->toBeNull();
});

test('the list filters by assignee, status and overdue', function () {
    $operator = User::factory()->crmAdmin()->create();
    $other = secondOperator();

    CrmTask::factory()->standalone()->assignedTo($operator)->overdue()->create();
    CrmTask::factory()->standalone()->assignedTo($other)->create();
    CrmTask::factory()->standalone()->create(); // unassigned
    CrmTask::factory()->standalone()->done()->create();

    $assertCount = function (array $query, int $expected) use ($operator) {
        $this->actingAs($operator)
            ->get(route('crm.tasks.index', $query))
            ->assertInertia(fn (Assert $page) => $page->has('tasks.data', $expected));
    };

    $assertCount(['assignee' => 'me'], 1);
    $assertCount(['assignee' => (string) $other->id], 1);
    $assertCount(['assignee' => 'unassigned'], 2);
    $assertCount(['status' => 'done'], 1);
    $assertCount(['overdue' => 1], 1);
});

test('tasks are deleted and contact deletion takes its tasks with it', function () {
    $operator = User::factory()->crmAdmin()->create();
    $task = CrmTask::factory()->standalone()->create();

    $this->actingAs($operator)
        ->delete(route('crm.tasks.destroy', $task))
        ->assertRedirect();

    $this->assertDatabaseMissing('crm_tasks', ['id' => $task->id]);

    // Contacts soft-delete, so their tasks survive the CRM delete button and
    // only cascade when the row is force-deleted.
    $contact = CrmContact::factory()->create();
    $attached = CrmTask::factory()->create(['crm_contact_id' => $contact->id]);

    $contact->delete();
    $this->assertDatabaseHas('crm_tasks', ['id' => $attached->id]);

    $contact->forceDelete();
    $this->assertDatabaseMissing('crm_tasks', ['id' => $attached->id]);
});

test('the contact page carries its tasks and the assignee options', function () {
    $operator = User::factory()->crmAdmin()->create();
    $contact = CrmContact::factory()->create();
    CrmTask::factory()->assignedTo($operator)->create([
        'crm_contact_id' => $contact->id,
        'title' => 'Follow up',
    ]);

    $this->actingAs($operator)
        ->get(route('crm.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Show')
            ->has('contact.tasks', 1)
            ->where('contact.tasks.0.title', 'Follow up')
            ->where('contact.tasks.0.assignee.name', $operator->name)
            ->has('options.assignees', 1)
            ->has('options.taskStatuses', 4)
        );
});
