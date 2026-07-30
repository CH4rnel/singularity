<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use kornrunner\Keccak;

beforeEach(function () {
    $this->withoutVite();
});

it('shows a public feed with newest posts first', function () {
    $older = Post::factory()->create();
    $canonicalAuthor = User::factory()->create([
        'name' => 'Local Name',
        'onchain_nickname' => 'chain_name',
    ]);
    $newer = Post::factory()->for($canonicalAuthor)->create();

    $this->get(route('feed'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Feed')
            ->has('posts.data', 2)
            ->where('posts.data.0.id', $newer->id)
            ->where('posts.data.1.id', $older->id)
            ->where('posts.data.0.user.name', 'chain_name')
            ->where('posts.data.0.user.profile_url', '/chain_name')
            ->has('posts.data.0.user.avatar'));
});

it('lets signed-in users create posts but rejects invalid content', function () {
    $user = User::factory()->create();

    $this->post(route('posts.store'), ['body' => 'Not authenticated'])
        ->assertRedirect(route('login'));

    $this->actingAs($user)
        ->post(route('posts.store'), [
            'body' => 'Hello from the Cyberia social layer.',
            'user_id' => User::factory()->create()->id,
        ])
        ->assertSessionHas('status', 'post-created');

    $post = Post::query()->sole();

    $this->assertModelExists($post);
    expect($post->user_id)->toBe($user->id)
        ->and($post->body)->toBe('Hello from the Cyberia social layer.');

    $this->actingAs($user)
        ->post(route('posts.store'), ['body' => str_repeat('x', 2001)])
        ->assertSessionHasErrors('body');
});

it('renders a public wall without exposing private account fields', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->create();
    Post::factory()->create();

    $this->get(route('users.legacy', $user))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id)
            ->missing('profile.email')
            ->where('stats.posts', 1)
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $post->id));
});

it('stores a validated avatar and exposes its public URL', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $oldPath = "avatars/{$user->id}/old.png";
    Storage::disk('public')->put($oldPath, 'old avatar');
    $user->forceFill(['avatar_path' => $oldPath])->save();

    $this->actingAs($user)
        ->from(route('profile.show'))
        ->post(route('profile.avatar'), [
            'avatar' => UploadedFile::fake()->image('avatar.png', 256, 256)->size(100),
        ])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHas('status', 'avatar-updated');

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull()
        ->and($user->avatar)->toEndWith('/storage/'.$user->avatar_path);
    Storage::disk('public')->assertExists($user->avatar_path);
    Storage::disk('public')->assertMissing($oldPath);

    $this->get(route('users.legacy', $user))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.avatar', $user->avatar));
});

it('rejects unsafe or oversized avatar uploads', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.show'))
        ->post(route('profile.avatar'), [
            'avatar' => UploadedFile::fake()->create(
                'avatar.svg',
                10,
                'image/svg+xml',
            ),
        ])
        ->assertSessionHasErrors('avatar');

    $this->actingAs($user)
        ->from(route('profile.show'))
        ->post(route('profile.avatar'), [
            'avatar' => UploadedFile::fake()->image('huge.png')->size(2049),
        ])
        ->assertSessionHasErrors('avatar');

    expect($user->fresh()->avatar_path)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('always prefers the cached on-chain nickname over the local account name', function () {
    $user = User::factory()->create([
        'name' => 'Local Name',
        'onchain_nickname' => 'chain_name',
    ]);
    $fallback = User::factory()->create([
        'name' => 'Fallback Name',
        'onchain_nickname' => null,
    ]);

    expect($user->name)->toBe('chain_name')
        ->and($fallback->name)->toBe('Fallback Name');
});

it('refreshes the canonical nickname from chain for every public surface', function () {
    $contract = '0x00000000000000000000000000000000000000cc';
    $wallet = '0x5555555555555555555555555555555555555555';
    $nickname = 'chain_first';
    $hex = bin2hex($nickname);
    $encodedNickname = '0x'
        .str_pad('20', 64, '0', STR_PAD_LEFT)
        .str_pad(dechex(strlen($nickname)), 64, '0', STR_PAD_LEFT)
        .str_pad($hex, 64, '0');
    $selector = '0x'.substr(Keccak::hash('nicknameOf(address)', 256), 0, 8);

    config()->set('services.profile.contract_address', $contract);
    config()->set('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');

    Http::fake([
        'rpc.cyberia.church' => function ($request) use ($encodedNickname, $selector) {
            $data = (string) ($request->data()['params'][0]['data'] ?? '');

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => str_starts_with($data, $selector) ? $encodedNickname : '0x',
            ]);
        },
    ]);

    $user = User::factory()->create([
        'name' => 'Local Name',
        'wallet_address' => $wallet,
    ]);

    $this->get(route('users.legacy', $user))
        ->assertStatus(301)
        ->assertRedirect(route('users.show', ['user' => $nickname]));

    $this->get(route('users.show', ['user' => $nickname]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.name', $nickname));

    expect($user->fresh()->onchain_nickname)->toBe($nickname);
});
