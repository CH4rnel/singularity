<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Pinning from the wallet.
 *
 * The endpoint holds no key and knows no account, so what there is to test is
 * exactly this: the bytes reach the node unchanged, the CID comes back with
 * both ways of addressing it, a page is wrapped so a gateway renders it as a
 * site, and the caps are real.
 */
const PIN_FILE_CID = 'bafkreiey23hc4qrq7geaemvfzrhnahnywt3ro5cf76vty6ebuubcnxvz7a';
const PIN_DIR_CID = 'bafybeigdyrzt5sfp7udm7hu76uh7y26nf3efuylqabf3oclgtqy55fbzdi';

/** Kubo streams one JSON object per added object; the root is the last line. */
function pinKubo(string $cid, ?string $inner = null): void
{
    $body = $inner === null
        ? json_encode(['Name' => 'file', 'Hash' => $cid])."\n"
        : json_encode(['Name' => 'index.html', 'Hash' => $inner])."\n"
            .json_encode(['Name' => '', 'Hash' => $cid])."\n";

    Http::fake(['*/api/v0/add*' => Http::response($body, 200)]);
}

it('pins a file and answers with both ways of addressing it', function () {
    pinKubo(PIN_FILE_CID);

    $this->post('/api/wallet/ipfs/file', [
        'file' => UploadedFile::fake()->createWithContent('metadata.json', '{"name":"lain"}'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('cid', PIN_FILE_CID)
        ->assertJsonPath('ipfs_uri', 'ipfs://'.PIN_FILE_CID.'/')
        ->assertJsonPath('gateway_url', config('ipfs.gateway').'/ipfs/'.PIN_FILE_CID.'/')
        ->assertJsonPath('name', 'metadata.json');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pin=true')
        && str_contains($request->url(), 'cid-version=1')
        && ! str_contains($request->url(), 'wrap-with-directory'));
});

it('wraps a page in a directory so the bare CID renders as a site', function () {
    pinKubo(PIN_DIR_CID, PIN_FILE_CID);

    $this->post('/api/wallet/ipfs/page', [
        'html' => '<!doctype html><title>wired</title><h1>hello</h1>',
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('cid', PIN_DIR_CID)
        ->assertJsonPath('name', 'index.html');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'wrap-with-directory=true'));
});

it('sends the bytes it was given, unchanged', function () {
    pinKubo(PIN_FILE_CID);
    $bytes = '{"name":"лейн","image":"ipfs://'.PIN_FILE_CID.'"}';

    $this->post('/api/wallet/ipfs/file', [
        'file' => UploadedFile::fake()->createWithContent('metadata.json', $bytes),
    ], ['Accept' => 'application/json'])->assertOk();

    Http::assertSent(fn (Request $request) => str_contains($request->body(), $bytes));
});

it('keeps only a safe basename for the name inside IPFS', function () {
    pinKubo(PIN_FILE_CID);

    $this->post('/api/wallet/ipfs/file', [
        'file' => UploadedFile::fake()->createWithContent('../../etc/pass wd;.json', 'x'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('name', 'pass-wd-.json');
});

it('refuses a page larger than the cap in bytes, not characters', function () {
    config()->set('wallet.ipfs.max_bytes', 1024);
    Http::fake();

    // Cyrillic is two bytes a character: 600 characters is 1200 bytes, which
    // is over the cap even though the string is shorter than it.
    $this->post('/api/wallet/ipfs/page', [
        'html' => str_repeat('л', 600),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('html');

    Http::assertNothingSent();
});

it('refuses a file larger than the cap', function () {
    config()->set('wallet.ipfs.max_bytes', 1024 * 1024);
    Http::fake();

    $this->post('/api/wallet/ipfs/file', [
        'file' => UploadedFile::fake()->create('big.bin', 2048),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    Http::assertNothingSent();
});

it('says pinning is off rather than failing at the node', function () {
    config()->set('wallet.ipfs.enabled', false);
    Http::fake();

    $this->post('/api/wallet/ipfs/page', ['html' => '<h1>x</h1>'], ['Accept' => 'application/json'])
        ->assertStatus(503);

    Http::assertNothingSent();
});

it('reports an unreachable node without claiming a pin', function () {
    Http::fake(['*/api/v0/add*' => Http::response('no route to host', 500)]);

    $this->post('/api/wallet/ipfs/file', [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'x'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'The IPFS node did not accept this. Nothing was pinned.');
});
