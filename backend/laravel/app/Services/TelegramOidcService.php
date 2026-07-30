<?php

namespace App\Services;

use App\Enums\SocialProvider;
use App\Exceptions\SocialIdentityConflictException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

class TelegramOidcService
{
    public function __construct(
        private readonly SocialLinkState $linkState,
    ) {}

    public function configured(): bool
    {
        return (string) config('services.telegram.client_id') !== ''
            && (string) config('services.telegram.client_secret') !== '';
    }

    public function authorizationUrl(Request $request): string
    {
        $state = Str::random(64);
        $verifier = Str::random(96);
        $nonce = Str::random(64);

        $this->linkState->start($request, SocialProvider::Telegram, [
            'state' => $state,
            'verifier' => $verifier,
            'nonce' => $nonce,
        ]);

        return (string) Uri::of((string) config('services.telegram.authorization_url'))
            ->withQuery([
                'client_id' => (string) config('services.telegram.client_id'),
                'redirect_uri' => $this->redirectUri(),
                'response_type' => 'code',
                'scope' => 'openid profile',
                'state' => $state,
                'nonce' => $nonce,
                'code_challenge' => $this->base64Url(hash('sha256', $verifier, binary: true)),
                'code_challenge_method' => 'S256',
            ], merge: false);
    }

    /**
     * @return array{id: string, username: ?string}
     *
     * @throws SocialIdentityConflictException
     */
    public function identity(Request $request): array
    {
        $context = $this->linkState->current($request, SocialProvider::Telegram);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if (
            $code === ''
            || $state === ''
            || ! hash_equals((string) ($context['state'] ?? ''), $state)
        ) {
            throw SocialIdentityConflictException::invalidIntent();
        }

        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->withBasicAuth(
                (string) config('services.telegram.client_id'),
                (string) config('services.telegram.client_secret'),
            )
            ->connectTimeout(5)
            ->timeout(10)
            ->retry([100, 300], throw: false)
            ->post((string) config('services.telegram.token_url'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => (string) config('services.telegram.client_id'),
                'code_verifier' => (string) ($context['verifier'] ?? ''),
            ]);

        $tokenResponse->throw();

        $idToken = $tokenResponse->json('id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new UnexpectedValueException('Telegram did not return an ID token.');
        }

        $claims = $this->verifiedClaims($idToken);
        $expectedNonce = (string) ($context['nonce'] ?? '');
        $subject = (string) ($claims->sub ?? '');

        if (
            $subject === ''
            || $expectedNonce === ''
            || ! hash_equals($expectedNonce, (string) ($claims->nonce ?? ''))
        ) {
            throw SocialIdentityConflictException::invalidIntent();
        }

        $this->linkState->finish($request, SocialProvider::Telegram);

        $username = $claims->preferred_username ?? null;

        return [
            'id' => $subject,
            'username' => is_string($username) && $username !== '' ? $username : null,
        ];
    }

    public function cancel(Request $request): void
    {
        $this->linkState->finish($request, SocialProvider::Telegram);
    }

    private function verifiedClaims(string $idToken): stdClass
    {
        $jwks = Cache::remember(
            'telegram_oidc.jwks',
            now()->addHours(6),
            function (): array {
                $response = Http::acceptJson()
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->retry([100, 300], throw: false)
                    ->get((string) config('services.telegram.jwks_url'));

                $response->throw();

                return $response->json();
            },
        );

        if (! is_array($jwks)) {
            throw new RuntimeException('Telegram JWKS response was invalid.');
        }

        $claims = JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));
        $audience = array_map(
            static fn (mixed $value): string => (string) $value,
            is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null],
        );

        if (
            ($claims->iss ?? null) !== 'https://oauth.telegram.org'
            || ! isset($claims->exp, $claims->iat)
            || ! is_numeric($claims->exp)
            || ! is_numeric($claims->iat)
            || ! in_array((string) config('services.telegram.client_id'), $audience, true)
        ) {
            throw new UnexpectedValueException('Telegram ID token claims were invalid.');
        }

        return $claims;
    }

    private function redirectUri(): string
    {
        $redirect = (string) config('services.telegram.redirect');

        return Str::startsWith($redirect, ['http://', 'https://'])
            ? $redirect
            : url($redirect);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
