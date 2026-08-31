<?php

use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config()->set('crm.admin_wallets', [
        '0x00000000000000000000000000000000000000aa',
    ]);
    config()->set('crm.admin_user_ids', []);
    Cache::flush();
});

function crmOperator(): User
{
    return User::factory()->create([
        'wallet_address' => '0x00000000000000000000000000000000000000aa',
    ]);
}

it('offers partner as a lead type and counts the partner segment', function () {
    CrmContact::factory()->create(['type' => 'partner']);
    CrmContact::factory()->create(['type' => 'lead']);

    $this->actingAs(crmOperator())
        ->get('/crm/people?segment=partners')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/People')
            ->where('segment', 'partners')
            ->where('total', 1)
            ->where('options.types', CrmContact::TYPES)
            ->where('segments', fn ($segments) => collect($segments)
                ->contains(fn (array $segment) => $segment['key'] === 'partners' && $segment['count'] === 1)));
});

it('moves an open task into progress', function () {
    $task = CrmTask::factory()->standalone()->create(['status' => 'open']);

    $this->actingAs(crmOperator())
        ->put("/crm/tasks/{$task->id}", ['status' => 'in_progress'])
        ->assertRedirect();

    expect($task->fresh()->status)->toBe('in_progress');
});
