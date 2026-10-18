<?php

use App\Models\BoardPost;
use App\Models\BoardThread;
use App\Models\User;
use App\Services\Board\BoardPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'crm.admin_user_ids' => [], 'crm.admin_wallets' => [], 'board.lain_user_id' => 0]);
    Http::preventStrayRequests();
    Storage::fake('local');
});

function boardMember(): User
{
    return User::factory()->create(['wallet_address' => '0x'.str_repeat('1', 40)]);
}

function boardOpening(?User $user = null): BoardPost
{
    return app(BoardPublisher::class)->publish($user ?? boardMember(), ['title' => 'A real discussion', 'body' => 'A fixture publication.']);
}

function boardRpc(BoardPost $post, array $changes = [], array $receiptChanges = [], string $chain = '0xc0fe', string $head = '0x65', ?string $canonicalHash = null): string
{
    $hash = '0x'.str_repeat('a', 64);
    $blockHash = '0x'.str_repeat('b', 64);
    $tx = array_replace(['hash' => $hash, 'from' => $post->author_address, 'to' => $post->author_address, 'value' => '0x0', 'input' => $post->anchorData(), 'blockNumber' => '0x64', 'blockHash' => $blockHash], $changes);
    $receipt = array_replace(['transactionHash' => $hash, 'from' => $post->author_address, 'to' => $post->author_address, 'status' => '0x1', 'blockNumber' => '0x64', 'blockHash' => $blockHash], $receiptChanges);
    Http::fake([config('board.rpc_url') => fn ($request) => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => match ($request['method']) {
        'eth_chainId' => $chain, 'eth_getTransactionByHash' => $tx, 'eth_getTransactionReceipt' => $receipt,
        'eth_blockNumber' => $head, 'eth_getBlockByNumber' => ['hash' => $canonicalHash ?? $blockHash],
    }])]);

    return $hash;
}

it('shows an empty public board without inference, wallets or fabricated activity', function () {
    $this->get('/board')->assertSuccessful()->assertInertia(fn (Assert $page) => $page->component('Board')->has('threads.data', 0)->where('signedIn', false));
    $this->get('/board/b/missing')->assertNotFound();
    Http::assertNothingSent();
});

it('requires a session for writing and accepts a text-only thread without a wallet', function () {
    $this->post('/board/b/general/threads', ['title' => 'Hello', 'body' => 'World'])->assertRedirect('/login');
    $user = User::factory()->create(['name' => 'Private account name', 'email' => 'private@example.test', 'wallet_address' => null]);
    $this->actingAs($user)->post('/board/b/general/threads', ['title' => 'Hello', 'body' => 'World', 'actor' => 'agent', 'author' => 'Lain'])->assertRedirect();
    $post = BoardPost::query()->sole();
    expect($post->actor)->toBe('human')->and($post->author)->toStartWith('Anon #')->and($post->author_address)->toBeNull();
    $this->get('/board/t/'.$post->board_thread_id)->assertInertia(fn (Assert $page) => $page->where('posts.data.0.author', $post->author)->where('posts.data.0.canAnchor', false)->missing('posts.data.0.user_id')->missing('posts.data.0.author_address'));
    Http::assertNothingSent();
});

it('stores an image-only post as metadata-free WebP and hashes the final bytes', function () {
    $user = boardMember();
    $this->actingAs($user)->post('/board/b/build/threads', ['title' => 'My prototype', 'image' => UploadedFile::fake()->image('picture.png', 120, 80)])->assertRedirect();
    $post = BoardPost::query()->sole();
    $bytes = Storage::disk('local')->get($post->image_path);
    expect($post->image_hash)->toBe(hash('sha256', $bytes))->and($post->content_hash)->toBe(hash('sha256', $post->manifest()))->and(getimagesizefromstring($bytes)['mime'])->toBe('image/webp');
    $this->get('/board/posts/'.$post->id.'/image')->assertSuccessful()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('rejects active files, oversized images and empty publications', function () {
    $this->actingAs(boardMember())->post('/board/b/general/threads', ['title' => 'Bad', 'image' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertSessionHasErrors('image');
    $this->post('/board/b/general/threads', ['title' => 'Too big', 'image' => UploadedFile::fake()->image('x.png', 2401, 10)])->assertSessionHasErrors('image');
    $this->post('/board/b/general/threads', ['title' => 'Empty'])->assertSessionHasErrors('body');
    expect(BoardPost::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('caps image storage without refusing text or leaving files behind', function () {
    config(['board.storage_bytes' => 1]);
    $this->actingAs(boardMember())->post('/board/b/general/threads', ['title' => 'Image', 'image' => UploadedFile::fake()->image('x.png')])->assertSessionHasErrors('image');
    expect(Storage::disk('local')->allFiles())->toBe([])->and(BoardThread::query()->count())->toBe(0);
    $this->post('/board/b/general/threads', ['title' => 'Text', 'body' => 'Still available'])->assertRedirect();
    expect(BoardPost::query()->count())->toBe(1);
});

it('keeps aliases stable per thread and prevents replies to unrelated or closed threads', function () {
    $user = boardMember();
    $first = boardOpening($user);
    $second = boardOpening($user);
    $this->actingAs($user)->post('/board/t/'.$first->board_thread_id.'/posts', ['title' => '', 'image' => null, 'body' => 'A reply', 'parent_id' => (string) $first->id])->assertRedirect();
    $reply = BoardPost::query()->latest('id')->first();
    expect($reply->author)->toBe($first->author)->not->toBe($second->author)->and($reply->content_hash)->toBe(hash('sha256', $reply->manifest()));
    $this->post('/board/t/'.$first->board_thread_id.'/posts', ['body' => 'Wrong parent', 'parent_id' => $second->id])->assertSessionHasErrors('parent_id');
    $first->thread->update(['closed' => true]);
    $this->post('/board/t/'.$first->board_thread_id.'/posts', ['body' => 'Closed'])->assertSessionHasErrors('body');
    expect(BoardPost::query()->count())->toBe(3);
});

it('deduplicates reports, restricts moderation and hides attachments with their thread', function () {
    $author = boardMember();
    $post = app(BoardPublisher::class)->publish($author, ['title' => 'Opening', 'body' => 'Hello', 'image' => UploadedFile::fake()->image('a.png')]);
    $other = User::factory()->create();
    $this->actingAs($other)->delete('/board/posts/'.$post->id)->assertForbidden();
    $this->put('/board/t/'.$post->board_thread_id.'/closed')->assertForbidden();
    $this->post('/board/posts/'.$post->id.'/report')->assertRedirect();
    $this->post('/board/posts/'.$post->id.'/report')->assertRedirect();
    expect(DB::table('board_reports')->count())->toBe(1);
    config(['crm.admin_user_ids' => [$other->id]]);
    $this->get('/board/b/general?reported=1')->assertInertia(fn (Assert $page) => $page->has('threads.data', 1));
    $this->delete('/board/posts/'.$post->id)->assertRedirect();
    $this->get('/board/t/'.$post->board_thread_id)->assertNotFound();
    $this->get('/board/posts/'.$post->id.'/image')->assertNotFound();
    $this->get('/board/posts/'.$post->id.'/evidence')->assertNotFound();
});

it('does not reveal an unanchored wallet to guests, including after the account is deleted', function () {
    $post = boardOpening();
    $this->get('/board/posts/'.$post->id.'/evidence')->assertNotFound();
    $post->update(['user_id' => null]);
    $this->get('/board/posts/'.$post->id.'/evidence')->assertNotFound();
    $this->get('/board/t/'.$post->board_thread_id)->assertInertia(fn (Assert $page) => $page->where('posts.data.0.canRemove', false)->where('posts.data.0.canAnchor', false));
});

it('verifies a canonical zero-value commitment and makes the exact manifest public', function () {
    $user = boardMember();
    $post = boardOpening($user);
    $hash = boardRpc($post);
    $this->actingAs($user)->post('/board/posts/'.$post->id.'/anchor', ['tx_hash' => $hash])->assertRedirect();
    expect($post->fresh()->anchor_tx)->toBe($hash)->and($post->fresh()->anchor_block)->toBe(100);
    $this->post('/board/posts/'.$post->id.'/anchor', ['tx_hash' => $hash])->assertRedirect();
    $this->app['auth']->forgetGuards();
    $this->get('/board/posts/'.$post->id.'/evidence')->assertSuccessful()->assertContent($post->manifest());
});

it('rejects fabricated chain evidence', function (array $tx, array $receipt, string $chain, string $head, ?string $canonical) {
    $user = boardMember();
    $post = boardOpening($user);
    $hash = boardRpc($post, $tx, $receipt, $chain, $head, $canonical);
    $this->actingAs($user)->post('/board/posts/'.$post->id.'/anchor', ['tx_hash' => $hash])->assertSessionHasErrors('tx_hash');
    expect($post->fresh()->anchor_tx)->toBeNull();
})->with([
    'wrong sender' => [['from' => '0x'.str_repeat('2', 40)], [], '0xc0fe', '0x65', null],
    'wrong target' => [['to' => '0x'.str_repeat('2', 40)], [], '0xc0fe', '0x65', null],
    'wrong data' => [['input' => '0x1234'], [], '0xc0fe', '0x65', null],
    'value transfer' => [['value' => '0x1'], [], '0xc0fe', '0x65', null],
    'reverted' => [[], ['status' => '0x0'], '0xc0fe', '0x65', null],
    'wrong chain' => [[], [], '0x1', '0x65', null],
    'one confirmation' => [[], [], '0xc0fe', '0x64', null],
    'reorg' => [[], [], '0xc0fe', '0x65', '0x'.str_repeat('c', 64)],
    'missing block hash' => [['blockHash' => null], ['blockHash' => null], '0xc0fe', '0x65', null],
    'mismatched receipt' => [[], ['transactionHash' => '0x'.str_repeat('d', 64)], '0xc0fe', '0x65', null],
]);

it('fails closed on an unavailable RPC and refuses anchoring by another account', function () {
    $user = boardMember();
    $post = boardOpening($user);
    $other = User::factory()->create();
    $this->actingAs($other)->post('/board/posts/'.$post->id.'/anchor', ['tx_hash' => '0x'.str_repeat('a', 64)])->assertForbidden();
    Http::assertNothingSent();
    Http::fake([config('board.rpc_url') => Http::response([], 503)]);
    $this->actingAs($user)->post('/board/posts/'.$post->id.'/anchor', ['tx_hash' => '0x'.str_repeat('a', 64)])->assertSessionHasErrors('tx_hash');
    expect($post->fresh()->anchor_tx)->toBeNull();
});

it('requires an explicit scoped agent token and a configured agent identity', function () {
    $post = boardOpening();
    $agent = User::factory()->create();
    $token = $agent->createToken('Board fixture', ['board:write'])->plainTextToken;
    $url = '/api/board/threads/'.$post->board_thread_id.'/posts';
    $this->withToken($token)->postJson($url, ['body' => 'An agent reply'])->assertForbidden();
    config(['board.lain_user_id' => $agent->id]);
    $this->withToken($token)->postJson($url, ['body' => 'An agent reply'])->assertCreated()->assertJsonStructure(['id', 'hash', 'url']);
    expect(BoardPost::query()->latest('id')->first()->actor)->toBe('agent');
    $broad = $agent->createToken('Unscoped fixture')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($broad)->postJson($url, ['body' => 'No board grant'])->assertForbidden();
    Http::assertNothingSent();
});

it('keeps pending transactions unconfirmed and prevents transaction reuse', function () {
    $user = boardMember();
    $post = boardOpening($user);
    $other = boardOpening($user);
    $hash = '0x'.str_repeat('a', 64);
    Http::fake([config('board.rpc_url') => fn ($request) => Http::response(['result' => $request['method'] === 'eth_chainId' ? '0xc0fe' : null])]);
    $this->actingAs($user)->post('/board/posts/'.$post->id.'/anchor', ['tx_hash' => $hash])->assertSessionHasErrors('tx_hash');
    expect($post->fresh()->anchor_tx)->toBeNull();
    $post->update(['anchor_tx' => $hash, 'anchor_block' => 100]);
    Http::fake();
    $this->post('/board/posts/'.$other->id.'/anchor', ['tx_hash' => $hash])->assertSessionHasErrors('tx_hash');
    Http::assertNothingSent();
});

it('does not accept evidence changed while the RPC was being read', function () {
    $user = boardMember();
    $post = boardOpening($user);
    $hash = boardRpc($post);
    Http::preventStrayRequests();
    $blockHash = '0x'.str_repeat('b', 64);
    Http::fake([config('board.rpc_url') => function ($request) use ($post, $hash, $blockHash) {
        $result = match ($request['method']) {
            'eth_chainId' => '0xc0fe',
            'eth_getTransactionByHash' => ['hash' => $hash, 'from' => $post->author_address, 'to' => $post->author_address, 'value' => '0x0', 'input' => $post->anchorData(), 'blockNumber' => '0x64', 'blockHash' => $blockHash],
            'eth_getTransactionReceipt' => ['transactionHash' => $hash, 'from' => $post->author_address, 'to' => $post->author_address, 'status' => '0x1', 'blockNumber' => '0x64', 'blockHash' => $blockHash],
            'eth_blockNumber' => '0x65',
            'eth_getBlockByNumber' => ['hash' => $blockHash],
        };
        if ($request['method'] === 'eth_getBlockByNumber') {
            BoardPost::query()->whereKey($post->id)->update(['body' => 'Changed while verifying']);
        }

        return Http::response(['result' => $result]);
    }]);
    $this->actingAs($user)->post('/board/posts/'.$post->id.'/anchor', ['tx_hash' => $hash])->assertSessionHasErrors('tx_hash');
    expect($post->fresh()->anchor_tx)->toBeNull();
});

it('returns an agent permalink to the page containing its reply', function () {
    $post = boardOpening();
    $agent = User::factory()->create();
    config(['board.lain_user_id' => $agent->id]);
    BoardPost::factory()->count(30)->create(['board_thread_id' => $post->board_thread_id, 'user_id' => $agent->id]);
    $token = $agent->createToken('Board fixture', ['board:write'])->plainTextToken;
    $response = $this->withToken($token)->postJson('/api/board/threads/'.$post->board_thread_id.'/posts', ['body' => 'On the second page'])->assertCreated();
    expect($response->json('url'))->toContain('page=2#post-'.$response->json('id'));
});
