<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

test('the landing leads with the robinhood acquisition funnel', function () {
    $this->get('/')->assertOk();

    $landing = file_get_contents(resource_path('views/landing/index.html'));

    expect($landing)
        ->toContain('Bridge, trade and earn across Cyberia and Robinhood Chain')
        ->toContain('Open Robinhood bridge')
        ->toContain('Explore DEX')
        ->toContain('View staking');
});

test('the robinhood chain landing exposes only configured bridge routes', function () {
    $this->get('/robinhood-chain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Growth/RobinhoodChain')
            ->has('bridgeRoutes')
            ->where('bridgeRoutes', function ($routes): bool {
                $robinhoodRoutes = collect($routes);

                return $robinhoodRoutes->isNotEmpty()
                    && $robinhoodRoutes->every(
                        fn (array $route): bool => in_array(
                            'robinhood',
                            [$route['source'], $route['destination']],
                            true,
                        ),
                    )
                    && $robinhoodRoutes->contains(
                        fn (array $route): bool => $route['direction'] === 'robinhood_to_evm'
                            && $route['operational'] === true
                            && $route['tokens'] === ['ETH', 'SPY', 'CYBER'],
                    )
                    && $robinhoodRoutes->contains(
                        fn (array $route): bool => $route['direction'] === 'evm_to_robinhood'
                            && $route['operational'] === false
                            && $route['unavailableReason'] === 'Coming soon',
                    );
            })
            ->where('seo.title', 'Robinhood Chain Bridge, DEX and Staking | Cyberia')
            ->where('seo.canonical', 'https://cyberia.church/robinhood-chain')
            ->has('faq', 4));
});

test('partner campaigns render from the shared campaign page', function (string $partner) {
    $this->get("/partners/{$partner}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Growth/Partner')
            ->where('partnerSlug', $partner));
})->with(['ash', 'hatcher', 'orbserv']);

test('pioneer season is available as a coming soon experience', function () {
    $this->get('/pioneer-season')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Growth/PioneerSeason'));
});
