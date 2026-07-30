<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;

beforeEach(function () {
    config()->set([
        'services.github.client_id' => 'github-client',
        'services.github.client_secret' => 'github-secret',
        'services.github.redirect' => 'http://localhost/settings/connections/github/callback',
        'services.telegram.client_id' => 'telegram-client',
        'services.telegram.client_secret' => 'telegram-secret',
        'services.telegram.redirect' => 'http://localhost/settings/connections/telegram/callback',
        'services.telegram.authorization_url' => 'https://oauth.telegram.test/auth',
        'services.telegram.token_url' => 'https://oauth.telegram.test/token',
        'services.telegram.jwks_url' => 'https://oauth.telegram.test/jwks',
    ]);

    Cache::flush();
    Http::preventStrayRequests();
});

function fakeGithubUser(string $id = 'github-123', ?string $nickname = 'lain'): void
{
    Socialite::fake('github', OAuthUser::fake([
        'id' => $id,
        'nickname' => $nickname,
    ]));
}

/**
 * @return array{private_key: string, jwks: array{keys: array<int, array<string, string>>}}
 */
function telegramTestKeys(): array
{
    static $keys;

    if (is_array($keys)) {
        return $keys;
    }

    $resource = openssl_pkey_new([
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($resource === false || ! openssl_pkey_export($resource, $privateKey)) {
        throw new RuntimeException('Could not create the test RSA key.');
    }

    $details = openssl_pkey_get_details($resource);

    if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
        throw new RuntimeException('Could not read the test RSA key.');
    }

    return $keys = [
        'private_key' => $privateKey,
        'jwks' => [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'telegram-test-key',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => base64UrlForSocialTest($details['rsa']['n']),
                'e' => base64UrlForSocialTest($details['rsa']['e']),
            ]],
        ],
    ];
}

function base64UrlForSocialTest(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function telegramTestIdToken(string $nonce, array $overrides = []): string
{
    $now = now()->timestamp;
    $claims = array_replace([
        'iss' => 'https://oauth.telegram.org',
        'aud' => 'telegram-client',
        'sub' => 'telegram-123',
        'preferred_username' => 'lain_telegram',
        'nonce' => $nonce,
        'iat' => $now,
        'exp' => $now + 300,
    ], $overrides);

    return JWT::encode(
        $claims,
        telegramTestKeys()['private_key'],
        'RS256',
        'telegram-test-key',
    );
}

/**
 * @return array<string, string>
 */
function startTelegramLink($test, User $user): array
{
    $response = $test->actingAs($user)
        ->get(route('settings.connections.telegram.redirect'))
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return array_map(static fn (mixed $value): string => (string) $value, $query);
}

function fakeTelegramTokenEndpoint(string $idToken): void
{
    Http::fake([
        'https://oauth.telegram.test/token' => Http::response([
            'id_token' => $idToken,
            'token_type' => 'Bearer',
        ]),
        'https://oauth.telegram.test/jwks' => Http::response(telegramTestKeys()['jwks']),
    ]);
}

test('social connection endpoints require an authenticated user', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
    expect(User::query()->count())->toBe(0);
})->with([
    'GitHub redirect' => 'settings.connections.github.redirect',
    'GitHub callback' => 'settings.connections.github.callback',
    'Telegram redirect' => 'settings.connections.telegram.redirect',
    'Telegram callback' => 'settings.connections.telegram.callback',
]);

test('GitHub authorization requests only public account identity', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('settings.connections.github.redirect'))
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://github.com/login/oauth/authorize?')
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost/settings/connections/github/callback')
        ->and($query['scope'] ?? null)->toBe('')
        ->and($query['state'] ?? null)->not->toBeEmpty();
});

test('a signed-in user can link GitHub without creating another user', function () {
    $user = User::factory()->create();
    fakeGithubUser();

    $this->actingAs($user)
        ->get(route('settings.connections.github.redirect'))
        ->assertRedirect('https://socialite.fake/github/authorize');

    $this->get(route('settings.connections.github.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'GitHub account linked.');

    expect($user->fresh())
        ->github_id->toBe('github-123')
        ->github_username->toBe('lain')
        ->and(User::query()->count())->toBe(1);
});

test('linking the same GitHub id again refreshes its mutable username', function () {
    $user = User::factory()->create([
        'github_id' => 'github-123',
        'github_username' => 'old-name',
    ]);
    fakeGithubUser(nickname: 'new-name');

    $this->actingAs($user)->get(route('settings.connections.github.redirect'));
    $this->get(route('settings.connections.github.callback'))
        ->assertSessionHas('status', 'GitHub account linked.');

    expect($user->fresh())
        ->github_id->toBe('github-123')
        ->github_username->toBe('new-name');
});

test('GitHub cannot replace an existing link or take another user account', function (array $current, string $incoming) {
    User::factory()->create(['github_id' => $current['owner']]);
    $user = User::factory()->create([
        'github_id' => $current['self'],
        'github_username' => 'unchanged',
    ]);
    fakeGithubUser($incoming, 'intruder');

    $this->actingAs($user)->get(route('settings.connections.github.redirect'));
    $this->get(route('settings.connections.github.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('error');

    expect($user->fresh())
        ->github_id->toBe($current['self'])
        ->github_username->toBe('unchanged');
})->with([
    'replace current link' => [
        ['owner' => 'somebody-else', 'self' => 'my-github'],
        'different-github',
    ],
    'take occupied link' => [
        ['owner' => 'occupied-github', 'self' => null],
        'occupied-github',
    ],
]);

test('GitHub callback is rejected after the signed-in user changes', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    fakeGithubUser();

    $this->actingAs($firstUser)->get(route('settings.connections.github.redirect'));
    $this->actingAs($secondUser)
        ->get(route('settings.connections.github.callback'))
        ->assertSessionHas('error');

    expect($firstUser->fresh()->github_id)->toBeNull()
        ->and($secondUser->fresh()->github_id)->toBeNull();
});

test('Telegram authorization uses state nonce and PKCE then links the verified subject', function () {
    $user = User::factory()->create();
    $query = startTelegramLink($this, $user);

    expect($query)
        ->client_id->toBe('telegram-client')
        ->redirect_uri->toBe('http://localhost/settings/connections/telegram/callback')
        ->response_type->toBe('code')
        ->scope->toBe('openid profile')
        ->state->not->toBeEmpty()
        ->nonce->not->toBeEmpty()
        ->code_challenge->not->toBeEmpty()
        ->code_challenge_method->toBe('S256');

    fakeTelegramTokenEndpoint(telegramTestIdToken($query['nonce']));

    $this->get(route('settings.connections.telegram.callback', [
        'code' => 'telegram-code',
        'state' => $query['state'],
    ]))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'Telegram account linked.');

    expect($user->fresh())
        ->telegram_id->toBe('telegram-123')
        ->telegram_username->toBe('lain_telegram')
        ->and(User::query()->count())->toBe(1);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth.telegram.test/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'telegram-code'
        && is_string($request['code_verifier'])
        && $request['code_verifier'] !== ''
        && str_starts_with($request->header('Authorization')[0] ?? '', 'Basic '));
});

test('Telegram refuses a state mismatch before exchanging a code', function () {
    $user = User::factory()->create();
    startTelegramLink($this, $user);
    Http::fake();

    $this->get(route('settings.connections.telegram.callback', [
        'code' => 'telegram-code',
        'state' => 'substituted-state',
    ]))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('error');

    expect($user->fresh()->telegram_id)->toBeNull();
    Http::assertNothingSent();
});

test('Telegram refuses a signed token with the wrong nonce or expired claims', function (array $claimOverrides) {
    $user = User::factory()->create();
    $query = startTelegramLink($this, $user);
    fakeTelegramTokenEndpoint(telegramTestIdToken($query['nonce'], $claimOverrides));

    $this->get(route('settings.connections.telegram.callback', [
        'code' => 'telegram-code',
        'state' => $query['state'],
    ]))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('error');

    expect($user->fresh()->telegram_id)->toBeNull();
})->with([
    'wrong nonce' => [['nonce' => 'substituted-nonce']],
    'expired token' => [['exp' => 1]],
]);

test('Telegram cannot take an identity linked to another user', function () {
    User::factory()->create(['telegram_id' => 'telegram-123']);
    $user = User::factory()->create();
    $query = startTelegramLink($this, $user);
    fakeTelegramTokenEndpoint(telegramTestIdToken($query['nonce']));

    $this->get(route('settings.connections.telegram.callback', [
        'code' => 'telegram-code',
        'state' => $query['state'],
    ]))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('error');

    expect($user->fresh()->telegram_id)->toBeNull();
});

test('a Telegram callback cannot be replayed', function () {
    $user = User::factory()->create();
    $query = startTelegramLink($this, $user);
    fakeTelegramTokenEndpoint(telegramTestIdToken($query['nonce']));
    $callbackUrl = route('settings.connections.telegram.callback', [
        'code' => 'telegram-code',
        'state' => $query['state'],
    ]);

    $this->get($callbackUrl)->assertSessionHas('status', 'Telegram account linked.');
    $this->get($callbackUrl)->assertSessionHas('error');

    expect($user->fresh()->telegram_id)->toBe('telegram-123');
});
