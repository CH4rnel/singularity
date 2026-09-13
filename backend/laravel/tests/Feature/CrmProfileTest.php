<?php

use App\Models\CrmOperatorProfile;
use App\Models\CrmTask;
use App\Models\User;

test('only an operator can register a private CRM profile', function () {
    $this->withoutVite();
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(route('crm.profile.show'))->assertNotFound();
    $operator = User::factory()->crmAdmin()->create();
    $this->actingAs($operator)->post(route('crm.profile.update'), [
        'display_name' => 'Night Operator', 'role' => 'Support', 'bio' => 'On duty',
        'contacts' => ['email' => 'ops@example.test', 'telegram' => '@ops', 'website' => 'https://example.test'],
    ])->assertSessionHasNoErrors();
    $profile = CrmOperatorProfile::sole();
    expect($profile->user_id)->toBe($operator->id)->and($profile->registered_at)->not->toBeNull();
    $this->get(route('crm.profile.show'))->assertInertia(fn ($page) => $page->component('crm/Profile')->where('profile.display_name', 'Night Operator'));
});

test('theme is validated and activity comes from CRM records', function () {
    $this->withoutVite();
    $operator = User::factory()->crmAdmin()->create();
    CrmTask::factory()->count(5)->assignedTo($operator)->done()->create();
    $this->actingAs($operator)->patchJson(route('crm.profile.theme'), ['theme' => 'photon'])->assertExactJson(['theme' => 'photon']);
    $this->patchJson(route('crm.profile.theme'), ['theme' => 'evil'])->assertUnprocessable();
    $this->get(route('crm.profile.show'))->assertInertia(fn ($page) => $page->where('profile.theme', 'photon')->where('stats.completed', 5)->where('stats.rank', 1));
});
