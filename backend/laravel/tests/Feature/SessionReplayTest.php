<?php

use App\Models\User;

/**
 * Who Microsoft Clarity records, decided before the tag is sent.
 *
 * Pinned: an ordinary visitor gets the tag on Inertia pages and on the static
 * landing page; operators, store builds, the desktop shell, a browser saying
 * Do Not Track and an unconfigured project get nothing; and the screens that
 * hold secrets load the tag paused rather than recording their first frame.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('services.clarity.enabled', true);
    config()->set('services.clarity.project_id', 'cyb3r1a');
    config()->set('analytics.respect_dnt', true);
    config()->set('crm.admin_user_ids', []);
    config()->set('crm.admin_wallets', []);
});

it('records an ordinary visitor', function () {
    $this->get(route('changelog'))
        ->assertOk()
        ->assertSee('window.cyberiaReplay', false)
        ->assertSee('"project":"cyb3r1a"', false)
        ->assertSee('"autostart":true', false);
});

it('records the static landing page too', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('window.cyberiaReplay')
        ->and(strpos($html, 'window.cyberiaReplay'))->toBeLessThan(strpos($html, '</head>'));
});

it('serves the landing page untouched when nothing is recorded', function () {
    config()->set('services.clarity.project_id', '');

    expect($this->get(route('home'))->assertOk()->getContent())
        ->toBe(file_get_contents(resource_path('views/landing/index.html')));
});

it('loads the tag paused on screens that hold secrets', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('window.cyberiaReplay', false)
        ->assertSee('"autostart":false', false);
});

it('never records an operator', function () {
    $operator = User::factory()->create();
    config()->set('crm.admin_user_ids', [$operator->id]);

    $this->actingAs($operator)
        ->get(route('changelog'))
        ->assertOk()
        ->assertDontSee('window.cyberiaReplay', false);
});

it('respects a refusal or a build that declares none', function (array $headers) {
    $this->withHeaders($headers)
        ->get(route('changelog'))
        ->assertOk()
        ->assertDontSee('window.cyberiaReplay', false);
})->with([
    'do not track' => [['DNT' => '1']],
    'global privacy control' => [['Sec-GPC' => '1']],
    'store edition' => [['User-Agent' => 'Mozilla/5.0 CyberiaMobile/1.4.0 CyberiaStore/play']],
    'desktop shell' => [['User-Agent' => 'Mozilla/5.0 CyberiaDesktop/1.4.0']],
]);

it('sends nothing until a project is configured', function () {
    config()->set('services.clarity.project_id', '');

    $this->get(route('changelog'))
        ->assertOk()
        ->assertDontSee('window.cyberiaReplay', false);
});
