<?php

use App\Actions\Wallet\RecoverEvmAddress;
use App\Models\LaunchpadToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const TOKEN_ADDRESS = '0x1111111111111111111111111111111111111111';
const CREATOR_ADDRESS = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const VALID_SIGNATURE = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const SITE_CID = 'bafybeigdyrzt5sfp7udm7hu76uh7y26nf3efuylqabf3oclgtqy55fbzdi';

/** Kubo streams one JSON object per added object; the wrapping directory is last. */
function kuboAddBody(string $cid): string
{
    return json_encode(['Name' => 'index.html', 'Hash' => 'bafyfilecidfilecidfilecidfilecid'])."\n"
        .json_encode(['Name' => '', 'Hash' => $cid])."\n";
}

/**
 * Stub the node's add endpoint, one CID per call. Stubs can only be registered
 * once per test, so a run that pins twice passes both CIDs here.
 */
function fakeKubo(string ...$cids): void
{
    $cids = $cids ?: [SITE_CID];
    $sequence = Http::sequence();

    foreach ($cids as $cid) {
        $sequence->push(kuboAddBody($cid), 200);
    }

    Http::fake(['*/api/v0/add*' => $sequence->whenEmpty(Http::response(kuboAddBody(end($cids))))]);
}

beforeEach(function () {
    Storage::fake('public');
    $this->mock(RecoverEvmAddress::class)
        ->shouldReceive('handle')
        ->andReturn(CREATOR_ADDRESS);
});

it('publishes uploaded token html on its chosen subdomain', function () {
    fakeKubo();
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
        ->assertJsonPath('token.site_url', 'https://lain.cyberia.church/')
        ->assertJsonPath('token.ipfs_cid', SITE_CID)
        ->assertJsonPath('token.ipfs_uri', 'ipfs://'.SITE_CID.'/')
        ->assertJsonPath('token.ipfs_url', 'https://ipfs.io/ipfs/'.SITE_CID.'/');

    // Wrapped in a directory so a gateway renders the CID as a page.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'wrap-with-directory=true')
        && str_contains($request->url(), 'pin=true'));

    $this->get('https://lain.cyberia.church/manifesto/chapter-1')
        ->assertOk()
        ->assertSee('hello, wired', false)
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Link', '<https://ipfs.io/ipfs/'.SITE_CID.'/>; rel="canonical"')
        ->assertHeader('X-Ipfs-Cid', SITE_CID);
});

it('addresses a site with no subdomain by its CID', function () {
    fakeKubo();
    $message = 'Edit Cyberia Launchpad metadata for '.TOKEN_ADDRESS.' at '.now()->toIso8601String();

    $this->post('/api/launchpad/tokens', [
        'address' => TOKEN_ADDRESS,
        'message' => $message,
        'signature' => VALID_SIGNATURE,
        'html' => UploadedFile::fake()->createWithContent('index.html', '<h1>no name</h1>'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('token.site_subdomain', null)
        ->assertJsonPath('token.site_url', 'https://ipfs.io/ipfs/'.SITE_CID.'/');
});

it('replaces the CID when the page is replaced', function () {
    fakeKubo(SITE_CID, 'bafybeiaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    $message = 'Edit Cyberia Launchpad metadata for '.TOKEN_ADDRESS.' at '.now()->toIso8601String();
    $payload = [
        'address' => TOKEN_ADDRESS,
        'message' => $message,
        'signature' => VALID_SIGNATURE,
    ];

    $this->post('/api/launchpad/tokens', $payload + [
        'html' => UploadedFile::fake()->createWithContent('index.html', '<h1>first</h1>'),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->post('/api/launchpad/tokens', $payload + [
        'html' => UploadedFile::fake()->createWithContent('index.html', '<h1>second</h1>'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('token.ipfs_cid', 'bafybeiaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
});

it('accepts an upload while the IPFS node is down and pins it later', function () {
    // Down for the upload, back up by the time the command runs.
    Http::fake([
        '*/api/v0/add*' => Http::sequence()
            ->push('node is restarting', 500)
            ->whenEmpty(Http::response(kuboAddBody(SITE_CID))),
    ]);
    $message = 'Edit Cyberia Launchpad metadata for '.TOKEN_ADDRESS.' at '.now()->toIso8601String();

    $this->post('/api/launchpad/tokens', [
        'address' => TOKEN_ADDRESS,
        'message' => $message,
        'signature' => VALID_SIGNATURE,
        'html' => UploadedFile::fake()->createWithContent('index.html', '<h1>hello</h1>'),
        'site_subdomain' => 'lain',
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('token.site_url', 'https://lain.cyberia.church/')
        ->assertJsonPath('token.ipfs_cid', null);

    // The page is served either way; only its permanent address is missing.
    $this->get('https://lain.cyberia.church/')->assertOk()->assertHeaderMissing('X-Ipfs-Cid');

    $this->artisan('launchpad:pin-sites')->assertExitCode(0);

    expect(LaunchpadToken::first()->ipfs_cid)->toBe(SITE_CID);
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
