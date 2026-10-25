<?php

use App\Actions\Wallet\RecoverEvmAddress;
use App\Models\LaunchpadToken;
use Illuminate\Support\Facades\Storage;

/**
 * Where a launched token can be followed.
 *
 * The two links a buyer looks for first are X and Telegram, and the page could
 * not show either. They are stored the way `crm_contacts` stores them — bare,
 * collapsed from whatever spelling was typed — so the page decides how to draw
 * them and nothing depends on a creator pasting a canonical URL.
 */
const SOCIALS_TOKEN = '0x4444444444444444444444444444444444444444';
const SOCIALS_CREATOR = '0xcccccccccccccccccccccccccccccccccccccccc';
const SOCIALS_SIGNATURE = '0xcccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

beforeEach(function () {
    Storage::fake('public');
    $this->mock(RecoverEvmAddress::class)
        ->shouldReceive('handle')
        ->andReturn(SOCIALS_CREATOR);
});

function socialsMessage(): string
{
    return 'Edit Cyberia Launchpad metadata for '.SOCIALS_TOKEN.' on chain 49406 at '.now()->toIso8601String();
}

function postSocials(array $fields)
{
    return test()->post('/api/launchpad/tokens', array_merge([
        'address' => SOCIALS_TOKEN,
        'chain_id' => 49406,
        'message' => socialsMessage(),
        'signature' => SOCIALS_SIGNATURE,
    ], $fields), ['Accept' => 'application/json']);
}

it('takes a handle however the creator typed it', function () {
    postSocials([
        'x' => 'https://x.com/cyberia_network',
        'telegram' => '@cyberia_network_chat',
    ])
        ->assertOk()
        ->assertJsonPath('token.x_handle', 'cyberia_network')
        ->assertJsonPath('token.x_url', 'https://x.com/cyberia_network')
        ->assertJsonPath('token.telegram_handle', 'cyberia_network_chat')
        ->assertJsonPath('token.telegram_url', 'https://t.me/cyberia_network_chat');
});

it('keeps stored links when the field is not sent at all', function () {
    postSocials(['x' => '@lain'])->assertOk();
    postSocials(['description' => 'still here'])
        ->assertOk()
        ->assertJsonPath('token.x_handle', 'lain')
        ->assertJsonPath('token.description', 'still here');
});

it('takes a link down when the field is sent blank', function () {
    postSocials(['x' => '@lain', 'telegram' => '@lain'])->assertOk();
    postSocials(['x' => '', 'telegram' => '@lain'])
        ->assertOk()
        ->assertJsonPath('token.x_handle', null)
        ->assertJsonPath('token.x_url', null)
        ->assertJsonPath('token.telegram_handle', 'lain');
});

it('refuses websites that are not HTTP links with upload guidance', function (string $website) {
    postSocials(['website' => $website])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('website')
        ->assertJsonPath('errors.website.0', 'Use an http:// or https:// website URL. To publish a local HTML file, leave Website empty and select it under HTML page.');

    expect(LaunchpadToken::where('address', SOCIALS_TOKEN)->exists())->toBeFalse();
})->with([
    'local HTML file' => 'file:///home/CH4rnel_exe/Projects/CyberiaVPN/docs/launchpad/cvpn.html',
    'local path' => '/home/creator/cvpn.html',
    'javascript' => 'javascript:alert(1)',
    'FTP' => 'ftp://example.com/cvpn.html',
    'malformed URL' => 'not a URL',
]);

it('keeps a website the creator gave', function (string $website) {
    postSocials(['website' => $website])
        ->assertOk()
        ->assertJsonPath('token.website_url', $website);
})->with(['http://cyberia.church', 'https://cyberia.church']);

it('preserves an omitted website and removes one explicitly left empty', function () {
    postSocials(['website' => 'https://cyberia.church'])->assertOk();
    postSocials(['description' => 'updated'])
        ->assertOk()
        ->assertJsonPath('token.website_url', 'https://cyberia.church');
    postSocials(['website' => ''])
        ->assertOk()
        ->assertJsonPath('token.website_url', null);
});
