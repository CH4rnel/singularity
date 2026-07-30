<?php

use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
});

it('links the PWA metadata from the application shell', function () {
    $this->get(route('changelog'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<meta name="theme-color" content="#0b0f10">', false)
        ->assertSee(
            '<meta name="apple-mobile-web-app-capable" content="yes">',
            false,
        );
});

it('publishes a valid installable web app manifest', function () {
    $manifest = json_decode(
        file_get_contents(public_path('manifest.webmanifest')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest)
        ->toMatchArray([
            'id' => '/',
            'name' => 'Cyberia',
            'short_name' => 'Cyberia',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0b0f10',
            'theme_color' => '#0b0f10',
        ])
        ->and($manifest['icons'])->toHaveCount(3)
        ->and($manifest['icons'][0])->toMatchArray([
            'src' => '/pwa/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ])
        ->and($manifest['icons'][1]['sizes'])->toBe('512x512')
        ->and($manifest['icons'][2]['purpose'])->toBe('maskable');

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(Str::after($icon['src'], '/')))->toBeFile();
    }
});

it('keeps authenticated pages out of the service worker cache', function () {
    $serviceWorker = file_get_contents(public_path('sw.js'));

    preg_match(
        "/if \\(request\\.mode === 'navigate'\\) \\{(?<handler>.*?)\\n\\s*\\}/s",
        $serviceWorker,
        $matches,
    );

    expect($serviceWorker)
        ->toContain("const OFFLINE_URL = '/offline.html';")
        ->toContain("url.pathname.startsWith('/build/')")
        ->and($matches['handler'] ?? null)->not->toBeNull()
        ->toContain('fetch(request).catch')
        ->not->toContain('cacheFirst')
        ->not->toContain('cache.put');
});

it('ships a self contained offline fallback', function () {
    $offlinePage = file_get_contents(public_path('offline.html'));

    expect($offlinePage)
        ->toContain('You are outside the Wired.')
        ->toContain('<a href="/">Try again</a>')
        ->not->toContain('<script');
});
