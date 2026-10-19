<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet across the site, Telegram and the apps.
 *
 * Telegram's signing key is replaced by one generated per test, so the real
 * verification path runs against launch data these tests sign themselves.
 */
function telegramKeys(): string
{
    $pair = sodium_crypto_sign_keypair();

    config([
        'services.telegram_bot.id' => '12345',
        'services.telegram_bot.webapp_public_key' => bin2hex(sodium_crypto_sign_publickey($pair)),
    ]);

    return sodium_crypto_sign_secretkey($pair);
}

/** Launch data the way Telegram builds and signs it for third parties. */
function initData(string $secret, array $user, ?int $authDate = null): string
{
    $fields = [
        'auth_date' => (string) ($authDate ?? now()->timestamp),
        'query_id' => 'AAH',
        'user' => json_encode($user),
    ];
    ksort($fields);
    $lines = array_map(fn ($key) => "{$key}={$fields[$key]}", array_keys($fields));
    $signature = sodium_crypto_sign_detached("12345:WebAppData\n".implode("\n", $lines), $secret);

    return http_build_query($fields + [
        'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
        'hash' => 'ignored',
    ]);
}

function sealedPayload(array $overrides = []): array
{
    return array_merge([
        'version' => 2,
        'kdf' => 'PBKDF2-SHA-256',
        'iterations' => 310000,
        'salt' => base64_encode(random_bytes(16)),
        'iv' => base64_encode(random_bytes(12)),
        'ciphertext' => base64_encode(random_bytes(200)),
    ], $overrides);
}

it('signs a Telegram launch in as a new profile carrying that Telegram account', function () {
    $secret = telegramKeys();

    $this->postJson('/login/telegram-webapp', [
        'init_data' => initData($secret, ['id' => 777, 'first_name' => 'Lain', 'username' => 'lain']),
    ])->assertOk();

    $user = User::query()->where('telegram_id', '777')->sole();

    expect($user->name)->toBe('Lain')
        ->and($user->telegram_username)->toBe('lain');
    $this->assertAuthenticatedAs($user);
});

it('refuses launch data that was altered or is a day old', function () {
    $secret = telegramKeys();
    $tampered = str_replace('777', '778', initData($secret, ['id' => 777, 'first_name' => 'Lain']));
    $stale = initData($secret, ['id' => 777, 'first_name' => 'Lain'], now()->subDays(2)->timestamp);

    $this->postJson('/login/telegram-webapp', ['init_data' => $tampered])->assertUnauthorized();
    $this->postJson('/login/telegram-webapp', ['init_data' => $stale])->assertUnauthorized();
    $this->assertGuest();
});

it('finds the site profile whose wallet this Telegram account linked through the bot', function () {
    $secret = telegramKeys();
    $owner = User::factory()->create(['wallet_address' => '0xabc0000000000000000000000000000000000001']);

    if (! Schema::hasTable('tg_wallets')) {
        Schema::create('tg_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('address');
        });
    }
    DB::table('tg_wallets')->insert(['user_id' => 777, 'address' => '0xABC0000000000000000000000000000000000001']);

    $this->postJson('/login/telegram-webapp', [
        'init_data' => initData($secret, ['id' => 777, 'first_name' => 'Lain']),
    ])->assertOk();

    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh()->telegram_id)->toBe('777');
});

it('hands Telegram to the linked site profile when the Telegram one is empty', function () {
    $secret = telegramKeys();
    $empty = User::factory()->create(['telegram_id' => '777', 'wallet_address' => null]);
    $owner = User::factory()->create(['wallet_address' => '0xabc0000000000000000000000000000000000001']);

    if (! Schema::hasTable('tg_wallets')) {
        Schema::create('tg_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('address');
        });
    }
    DB::table('tg_wallets')->insert(['user_id' => 777, 'address' => '0xabc0000000000000000000000000000000000001']);

    $this->postJson('/login/telegram-webapp', [
        'init_data' => initData($secret, ['id' => 777, 'first_name' => 'Lain']),
    ])->assertOk();

    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh()->telegram_id)->toBe('777')
        ->and($empty->fresh()->telegram_id)->toBeNull();
});

it('keeps one sealed wallet per profile and returns it', function () {
    $user = User::factory()->create();
    $payload = sealedPayload();

    $this->actingAs($user)->putJson('/api/wallet/sync', [
        'address' => '0xABC0000000000000000000000000000000000001',
        'payload' => $payload,
    ])->assertOk();

    $this->actingAs($user)->getJson('/api/wallet/sync')
        ->assertOk()
        ->assertJsonPath('backup.address', '0xabc0000000000000000000000000000000000001')
        ->assertJsonPath('backup.payload.ciphertext', $payload['ciphertext']);
});

it('will not overwrite a different wallet unless told to', function () {
    $user = User::factory()->create();
    $first = '0xabc0000000000000000000000000000000000001';
    $second = '0xabc0000000000000000000000000000000000002';

    $this->actingAs($user)->putJson('/api/wallet/sync', ['address' => $first, 'payload' => sealedPayload()])->assertOk();
    $this->actingAs($user)->putJson('/api/wallet/sync', ['address' => $second, 'payload' => sealedPayload()])
        ->assertStatus(409)
        ->assertJsonPath('address', $first);
    $this->actingAs($user)->putJson('/api/wallet/sync', ['address' => $second, 'payload' => sealedPayload(), 'replace' => true])
        ->assertOk();

    expect($user->walletSyncBackup()->value('address'))->toBe($second);
});

it('refuses a weakly derived key, and anybody signed out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/wallet/sync', [
        'address' => '0xabc0000000000000000000000000000000000001',
        'payload' => sealedPayload(['iterations' => 1000]),
    ])->assertUnprocessable();

    auth()->logout();
    $this->getJson('/api/wallet/sync')->assertUnauthorized();
});
