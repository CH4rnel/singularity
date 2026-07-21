<?php

use App\Actions\Wallet\RecoverEvmAddress;
use App\Models\LaunchpadToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

const TOKEN_ADDRESS = '0x1111111111111111111111111111111111111111';
const CREATOR_ADDRESS = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const VALID_SIGNATURE = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

beforeEach(function () {
    Storage::fake('public');
    $this->mock(RecoverEvmAddress::class)
        ->shouldReceive('handle')
        ->andReturn(CREATOR_ADDRESS);
});

it('publishes uploaded token html on its chosen subdomain', function () {
    $html = '<!doctype html><title>Lain</title><h1>hello, wired</h1>';
    $message = 'Edit Cyberia Launchpad metadata for '.TOKEN_ADDRESS.' at '.now()->toIso8601String();

    $this->post('/api/launchpad/tokens', [
        'address' => TOKEN_ADDRESS,
        'message' => $message,
        'signature' => VALID_SIGNATURE,
        'name' => 'Lain',
        'symbol' => 'LAIN',
        'html' => UploadedFile::fake()->createWithContent('index.html', $html),
        'site_subdomain' => 'Lain',
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('token.site_subdomain', 'lain')
        ->assertJsonPath('token.site_url', 'https://lain.cyberia.church/');

    $this->get('https://lain.cyberia.church/manifesto/chapter-1')
        ->assertOk()
        ->assertSee('hello, wired', false)
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('rejects reserved and already claimed subdomains', function () {
    LaunchpadToken::create([
        'address' => TOKEN_ADDRESS,
        'creator' => CREATOR_ADDRESS,
        'html_path' => 'launchpad-sites/existing.html',
        'site_subdomain' => 'lain',
    ]);

    $message = 'Edit Cyberia Launchpad metadata for 0x2222222222222222222222222222222222222222 at '.now()->toIso8601String();
    $basePayload = [
        'address' => '0x2222222222222222222222222222222222222222',
        'message' => $message,
        'signature' => VALID_SIGNATURE,
        'html' => UploadedFile::fake()->create('index.html', 1, 'text/html'),
    ];

    $this->post('/api/launchpad/tokens', $basePayload + ['site_subdomain' => 'bridge'], [
        'Accept' => 'application/json',
    ])->assertUnprocessable()->assertJsonValidationErrors('site_subdomain');

    $this->post('/api/launchpad/tokens', $basePayload + ['site_subdomain' => 'lain'], [
        'Accept' => 'application/json',
    ])->assertUnprocessable()->assertJsonValidationErrors('site_subdomain');
});

it('returns not found for an unassigned token subdomain', function () {
    $this->get('https://unknown-token.cyberia.church/')->assertNotFound();
});
