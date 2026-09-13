<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * /whitepaper replaced a .docx download, so what is pinned here is the two
 * ways the replacement could be worse than the file: a phase list that
 * disagrees with its own headline counters, and a landing page still sending
 * readers to the document instead of the page.
 */
beforeEach(function () {
    $this->withoutVite();
});

it('renders the roadmap with the counters derived from the phases', function () {
    $this->get('/whitepaper')
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $props = $page->component('Whitepaper')->toArray()['props'];
            $phases = collect($props['phases']);

            // A headline saying "six shipped" over a list of five is the one
            // arithmetic error a roadmap makes on its own, and it outlives the
            // edit that caused it.
            foreach ($props['tally'] as $status => $count) {
                expect($count)->toBe(
                    $phases->where('status', $status)->count(),
                    "tally for {$status}"
                );
            }

            expect($phases)->not->toBeEmpty()
                ->and($phases->pluck('numeral')->duplicates())->toBeEmpty()
                ->and($phases->pluck('status')->unique()->diff(
                    ['live', 'progress', 'planned', 'vision']
                ))->toBeEmpty();
        });
});

it('keeps the original document downloadable and dated', function () {
    // The page states when the roadmap was written and when its statuses were
    // last looked at. An undated roadmap is read as current forever.
    $this->get('/whitepaper')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Whitepaper')
            ->where('document.href', '/Cyberia_Roadmap.docx')
            ->has('document.date')
            ->has('reviewedAt'));

    expect(public_path('Cyberia_Roadmap.docx'))->toBeFile();
});

it('sends the landing page to the page rather than to the file', function () {
    $landing = file_get_contents(resource_path('views/landing/index.html'));

    expect($landing)
        ->toContain('href="/whitepaper"')
        ->not->toContain('Cyberia_Roadmap.docx');
});
