<?php

namespace App\Services\Tracker;

use App\Models\TrackerRelease;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use kornrunner\Keccak;
use Throwable;

/**
 * Turning a minted token into a release.
 *
 * The invariant the whole tracker rests on: **no token, no release**. A
 * submission names a chain and a token id and nothing else — the owner, the
 * URI and every word of the description are read by this server, from the
 * chain and from the document the token points at. There is no field a
 * submitter can set directly, so there is nothing to lie in.
 *
 * That also decides what a takedown is. The row can be hidden, and the token
 * cannot be unminted; the index is a view over the chain rather than the
 * record, and it says so by keeping the token's address on every row.
 */
final class ReleaseRegistrar
{
    /**
     * Register the release a token names.
     *
     * @throws RegistrationFailed
     */
    public function register(int $chainId, string $tokenId): TrackerRelease
    {
        $chain = (array) config("tracker.chains.{$chainId}", []);
        $collection = (string) ($chain['collection'] ?? '');

        if ($collection === '') {
            throw new RegistrationFailed('Releases cannot be minted on that network.');
        }

        if (! preg_match('/^[0-9]{1,78}$/', $tokenId)) {
            throw new RegistrationFailed('That is not a token id.');
        }

        $existing = TrackerRelease::query()
            ->where('chain_id', $chainId)
            ->where('contract', strtolower($collection))
            ->where('token_id', $tokenId)
            ->first();

        $rpc = (string) ($chain['rpc_url'] ?? '');

        $owner = $this->owner($rpc, $collection, $tokenId);
        $uri = $this->tokenUri($rpc, $collection, $tokenId);

        $fields = ReleaseMetadata::parse($this->document($uri));

        // One info hash, one release. A second token minted over the same
        // content would split the swarm's counters between two rows and give
        // two different pages for one torrent; the first mint keeps it, and
        // the token that already holds the row may re-register to update it.
        $clash = TrackerRelease::query()
            ->where('info_hash', $fields['info_hash'])
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
            ->first();

        if ($clash !== null) {
            throw new RegistrationFailed('That torrent is already a release on this tracker.');
        }

        $release = $existing ?? new TrackerRelease;

        $release->fill($fields)->forceFill([
            'chain_id' => $chainId,
            'contract' => strtolower($collection),
            'token_id' => $tokenId,
            'owner' => strtolower($owner),
            'token_uri' => mb_substr($uri, 0, 2000),
        ])->save();

        return $release->refresh();
    }

    /**
     * Who holds the token now.
     *
     * Re-read on every registration and by `tracker:sync`, because ownership
     * is the one field on a release that changes without anybody touching this
     * server: a sold token moves the release with it.
     */
    public function owner(string $rpc, string $collection, string $tokenId): string
    {
        $result = $this->call($rpc, $collection, 'ownerOf(uint256)', $this->encodeUint($tokenId));

        if ($result === null || strlen($result) < 66) {
            throw new RegistrationFailed(
                'The chain does not know that token — check the id, or wait for the mint to be mined.',
            );
        }

        return '0x'.substr($result, -40);
    }

    public function tokenUri(string $rpc, string $collection, string $tokenId): string
    {
        $result = $this->call($rpc, $collection, 'tokenURI(uint256)', $this->encodeUint($tokenId));
        $uri = $result === null ? null : $this->decodeString($result);

        if ($uri === null || trim($uri) === '') {
            throw new RegistrationFailed('That token points at nothing.');
        }

        return trim($uri);
    }

    /**
     * The document behind a tokenURI.
     *
     * A stranger's URL, so: two schemes only, one timeout, one size cap, and
     * no redirect chain worth following past a few hops. An `ipfs://` goes
     * through the configured read gateway — the CID is the address, and the
     * gateway is just this server's way of fetching it.
     *
     * @return array<string, mixed>
     */
    public function document(string $uri): array
    {
        $url = $this->resolve($uri);
        $max = (int) config('tracker.metadata.max_bytes', 262144);

        try {
            $response = Http::timeout((int) config('tracker.metadata.timeout', 12))
                ->withHeaders(['Accept' => 'application/json'])
                ->maxRedirects(3)
                ->get($url);
        } catch (Throwable $e) {
            Log::info('Tracker metadata unreachable', ['uri' => $uri, 'error' => $e->getMessage()]);

            throw new RegistrationFailed('The token metadata could not be fetched. Try again in a moment.');
        }

        if (! $response->successful()) {
            throw new RegistrationFailed("The token metadata answered {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > $max) {
            throw new RegistrationFailed('The token metadata is larger than this index reads.');
        }

        $document = json_decode($body, true);

        if (! is_array($document)) {
            throw new RegistrationFailed('The token points at something that is not ERC-721 metadata.');
        }

        return $document;
    }

    /** `ipfs://CID/path` through the read gateway; https untouched. */
    private function resolve(string $uri): string
    {
        if (stripos($uri, 'ipfs://') === 0) {
            $path = ltrim(substr($uri, 7), '/');

            return rtrim((string) config('ipfs.gateway', 'https://ipfs.io'), '/').'/ipfs/'.$path;
        }

        if (preg_match('#^https://#i', $uri) === 1) {
            return $uri;
        }

        throw new RegistrationFailed(
            'This index reads ipfs:// and https:// token URIs. That token points at neither.',
        );
    }

    /** eth_call: 4-byte selector from the signature, then the arguments. */
    private function call(string $rpc, string $to, string $signature, string $argsHex): ?string
    {
        if ($rpc === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_call',
                'params' => [['to' => $to, 'data' => '0x'.substr(Keccak::hash($signature, 256), 0, 8).$argsHex], 'latest'],
            ]);

            $result = $response->json('result');

            return is_string($result) && str_starts_with($result, '0x') ? $result : null;
        } catch (Throwable $e) {
            Log::warning('Tracker chain read failed', [
                'signature' => $signature,
                'error' => $e->getMessage(),
            ]);

            // An unreadable chain is not "no such token": saying so would tell
            // a minter their token does not exist because our RPC blinked.
            throw new RegistrationFailed('The chain could not be read just now. Try again in a moment.');
        }
    }

    private function encodeUint(string $decimal): string
    {
        $hex = '';
        $value = $decimal;

        // Token ids are uint256 and PHP integers are not, so this divides the
        // decimal string down by hand rather than overflowing through float.
        while ($value !== '' && $value !== '0') {
            $carry = 0;
            $next = '';

            for ($i = 0; $i < strlen($value); $i++) {
                $current = $carry * 10 + (int) $value[$i];
                $next .= intdiv($current, 16);
                $carry = $current % 16;
            }

            $hex = dechex($carry).$hex;
            $value = ltrim($next, '0');
        }

        return str_pad($hex === '' ? '0' : $hex, 64, '0', STR_PAD_LEFT);
    }

    /** One ABI-encoded dynamic string. */
    private function decodeString(string $result): ?string
    {
        $data = substr($result, 2);

        if (strlen($data) < 128) {
            return null;
        }

        $length = (int) hexdec(substr($data, 64, 64));
        $bytes = substr($data, 128, $length * 2);

        if (strlen($bytes) < $length * 2) {
            return null;
        }

        $decoded = hex2bin($bytes);

        return $decoded === false ? null : $decoded;
    }
}
