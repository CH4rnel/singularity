<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Kubo HTTP API run by `services/ipfs/docker-compose.yml`.
 *
 * Everything here is content addressing: `add()` returns a CID, which names
 * the bytes rather than the host that happened to serve them. That is the
 * whole point for token sites — the CID keeps resolving after this server,
 * this domain and this company are gone, from any node that still pins it.
 */
class IpfsService
{
    /**
     * Pin `$contents` and return its CID (v1, so it is also valid as a
     * subdomain-gateway label).
     *
     * `$wrapWithDirectory` puts the file inside a directory object and returns
     * the *directory's* CID: a gateway then serves `index.html` for the bare
     * CID, which is what makes a pinned page behave like a site instead of a
     * download.
     *
     * @throws \RuntimeException when the node is unreachable or refuses the add
     */
    public function add(string $contents, string $filename, string $mime, bool $wrapWithDirectory = false): string
    {
        $endpoint = rtrim((string) config('ipfs.api_url'), '/')
            .'/api/v0/add?pin=true&cid-version=1'
            .($wrapWithDirectory ? '&wrap-with-directory=true' : '');

        $res = Http::timeout((int) config('ipfs.timeout'))
            ->attach('file', $contents, $filename, ['Content-Type' => $mime])
            ->post($endpoint);

        if (! $res->successful()) {
            throw new \RuntimeException('Kubo add failed: HTTP '.$res->status().' '.substr($res->body(), 0, 200));
        }

        // Kubo streams one JSON object per added object; the last line is the
        // root — the wrapping directory when one was asked for.
        $lines = preg_split('/\r?\n/', trim($res->body())) ?: [];
        $last = end($lines);
        $decoded = json_decode(is_string($last) ? $last : '', true);

        if (! is_array($decoded) || empty($decoded['Hash'])) {
            throw new \RuntimeException('Kubo add returned no Hash');
        }

        return (string) $decoded['Hash'];
    }

    /** The canonical `ipfs://` address of a CID, for clients that resolve it natively. */
    public function uri(string $cid, string $path = ''): string
    {
        return 'ipfs://'.$cid.$this->suffix($path);
    }

    /** The same content through the configured public gateway. */
    public function gatewayUrl(string $cid, string $path = ''): string
    {
        return rtrim((string) config('ipfs.gateway'), '/').'/ipfs/'.$cid.$this->suffix($path);
    }

    private function suffix(string $path): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? '/' : '/'.$path;
    }
}
