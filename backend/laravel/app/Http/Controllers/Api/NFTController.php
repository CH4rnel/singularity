<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * NFT helpers for the Cyberia NFT marketplace.
 *
 * Public flow:
 *   1) front-end POSTs the image + metadata fields to /api/nft/upload
 *   2) we pin the image and the ERC-721 metadata JSON
 *   3) we return `token_uri` — front-end calls CyberiaNFT.mint(token_uri)
 *
 * Two drivers (env `NFT_METADATA_DRIVER`):
 *   - `ipfs` (default): pins to a Kubo HTTP API at `IPFS_API_URL`
 *     (default `http://127.0.0.1:5001`). Returns ipfs://CID.
 *   - `local`: stores under storage/app/public/nft and returns a
 *     same-origin https URL. Convenient for dev, not decentralised — the URI
 *     lives forever on-chain so don't ship `local` to prod.
 */
class NFTController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Image is optional — an NFT can be just a link or a piece of text.
            'image' => ['nullable', 'image', 'max:2048'],
            // Optional ERC-721 metadata extras.
            'external_url' => ['nullable', 'url', 'max:300'],
            'attributes' => ['nullable', 'string', 'max:5000'], // JSON-encoded array
        ]);

        $driver = strtolower(env('NFT_METADATA_DRIVER', 'ipfs'));
        $imageFile = $request->file('image');

        try {
            $metadata = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
            ];

            if ($imageFile !== null) {
                if ($driver === 'local') {
                    $metadata['image'] = $this->storeLocal($imageFile, 'nft/images');
                } else {
                    $metadata['image'] = $this->pinToIpfs(
                        $imageFile->getRealPath(),
                        $imageFile->getClientOriginalName() ?: 'image',
                        $imageFile->getMimeType() ?: 'application/octet-stream',
                    );
                }
            }

            if (! empty($data['external_url'])) {
                $metadata['external_url'] = $data['external_url'];
            }
            if (! empty($data['attributes'])) {
                $attrs = json_decode($data['attributes'], true);
                if (is_array($attrs)) {
                    $metadata['attributes'] = $attrs;
                }
            }

            $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($driver === 'local') {
                $tokenUri = $this->storeLocalString($json, 'nft/metadata', 'application/json', '.json');
            } else {
                $tokenUri = $this->pinStringToIpfs($json, 'metadata.json', 'application/json');
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'IPFS upload failed: '.$e->getMessage(),
            ], 502);
        }

        return response()->json([
            'token_uri' => $tokenUri,
            'metadata' => $metadata,
        ]);
    }

    private function pinToIpfs(string $path, string $filename, string $mime): string
    {
        $apiUrl = rtrim(env('IPFS_API_URL', 'http://127.0.0.1:5001'), '/');
        $endpoint = $apiUrl.'/api/v0/add?pin=true&cid-version=1';

        $res = Http::timeout(30)
            ->attach('file', file_get_contents($path), $filename, ['Content-Type' => $mime])
            ->post($endpoint);

        if (! $res->successful()) {
            throw new \RuntimeException('Kubo add failed: HTTP '.$res->status().' '.substr($res->body(), 0, 200));
        }
        // Kubo streams one JSON object per pinned file, last line is the root.
        $lines = preg_split('/\r?\n/', trim($res->body())) ?: [];
        $last = end($lines);
        $decoded = json_decode($last ?: '', true);
        if (! is_array($decoded) || empty($decoded['Hash'])) {
            throw new \RuntimeException('Kubo add returned no Hash');
        }

        return 'ipfs://'.$decoded['Hash'];
    }

    private function pinStringToIpfs(string $body, string $filename, string $mime): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nftmeta_');
        file_put_contents($tmp, $body);
        try {
            return $this->pinToIpfs($tmp, $filename, $mime);
        } finally {
            @unlink($tmp);
        }
    }

    private function storeLocal($file, string $dir): string
    {
        $path = $file->store($dir, 'public');

        return Storage::disk('public')->url($path);
    }

    private function storeLocalString(string $body, string $dir, string $mime, string $ext): string
    {
        $name = bin2hex(random_bytes(16)).$ext;
        $rel = $dir.'/'.$name;
        Storage::disk('public')->put($rel, $body, ['ContentType' => $mime]);

        return Storage::disk('public')->url($rel);
    }
}
