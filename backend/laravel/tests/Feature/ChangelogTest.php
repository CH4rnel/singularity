<?php

use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

it('renders the changelog page', function () {
    $this->get(route('changelog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Changelog')
            ->where('currentVersion', 'v0.12.0')
            ->has('releases', 14)
            ->where('releases.0.version', 'v0.12.0')
            ->where('releases.0.date', '2026-08-16')
            ->where('releases.0.title', 'The wallet stops sending you elsewhere')
            ->has('releases.0.sections', 2)
            ->where('releases.0.sections.0.label', 'Added')
            ->has('releases.0.sections.0.items', 8)
            ->where('release.current.version', 'v0.12.0')
            ->where('release.changelogUrl', route('changelog')));
});

it('shares only the three latest releases for the header menu', function () {
    Config::set('changelog.current_version', 'v4');
    Config::set('changelog.releases', [
        releaseEntry('v4'),
        releaseEntry('v3'),
        releaseEntry('v2'),
        releaseEntry('v1'),
    ]);

    $this->get(route('changelog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentVersion', 'v4')
            ->has('releases', 4)
            ->has('release.recent', 3)
            ->where('release.recent.0.version', 'v4')
            ->where('release.recent.1.version', 'v3')
            ->where('release.recent.2.version', 'v2'));
});

function releaseEntry(string $version): array
{
    return [
        'version' => $version,
        'date' => '2026-07-16',
        'title' => "Release {$version}",
        'sections' => [
            [
                'label' => 'Added',
                'items' => ["Change {$version}"],
            ],
        ],
    ];
}
