<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IpfsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pinning for the wallet: bytes in, a CID out.
 *
 * The wallet is otherwise browser-side, and this endpoint does not change
 * that. It holds no key, reads no account and stores no record of who asked —
 * the Kubo API simply cannot be handed to a browser (it can run any node
 * command, which is why it listens on localhost only), so the bytes come
 * through here on their way to the node.
 *
 * What comes back is a CID, and a CID is the whole point: it names the bytes
 * rather than this server. A page pinned here keeps resolving from any node
 * that has it after this host, this domain and this company are gone. Nothing
 * here is a promise to keep pinning it — that is what `pin=true` on our node
 * means today, and why the wallet shows the CID rather than only a link.
 */
class WalletIpfsController extends Controller
{
    public function __construct(private readonly IpfsService $ipfs) {}

    /**
     * Pin one file. Metadata JSON, an image, a page someone typed — all bytes.
     *
     * The wallet composes ERC-721 metadata itself and posts it here as a file,
     * so this endpoint has no opinion about what a token looks like and cannot
     * put anything into someone's metadata that they did not write.
     */
    public function file(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.$this->maxKilobytes()],
        ]);

        $upload = $request->file('file');

        try {
            $cid = $this->ipfs->add(
                (string) file_get_contents($upload->getRealPath()),
                $this->safeName($upload->getClientOriginalName()),
                $upload->getMimeType() ?: 'application/octet-stream',
            );
        } catch (\Throwable $e) {
            return $this->unreachable($e);
        }

        return response()->json([
            'cid' => $cid,
            'ipfs_uri' => $this->ipfs->uri($cid),
            'gateway_url' => $this->ipfs->gatewayUrl($cid),
            'bytes' => $upload->getSize(),
            'name' => $this->safeName($upload->getClientOriginalName()),
        ]);
    }

    /**
     * Pin a web page.
     *
     * Wrapped in a directory and named `index.html`, which is the difference
     * between a page and a download: a gateway serves the bare CID as a site.
     * That is the same treatment launchpad token sites get, and it is why this
     * is a separate route rather than a flag on the one above — the returned
     * CID addresses a directory, and the URL that renders it ends in `/`.
     */
    public function page(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $data = $request->validate([
            // Bytes, not characters: `max` on a string counts characters, and
            // a page of Cyrillic is twice the bytes of its own length.
            'html' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen((string) $value) > $this->maxBytes()) {
                    $fail('This page is larger than this server will pin.');
                }
            }],
        ]);

        try {
            $cid = $this->ipfs->add(
                $data['html'],
                'index.html',
                'text/html',
                wrapWithDirectory: true,
            );
        } catch (\Throwable $e) {
            return $this->unreachable($e);
        }

        return response()->json([
            'cid' => $cid,
            'ipfs_uri' => $this->ipfs->uri($cid),
            'gateway_url' => $this->ipfs->gatewayUrl($cid),
            'bytes' => strlen($data['html']),
            'name' => 'index.html',
        ]);
    }

    /**
     * Whether this request may pin at all — the one place both routes ask.
     *
     * Today the only answer is the config switch: pinning is open, capped by
     * size and by the route throttle. A holding gate or an allowlist belongs
     * here, where it changes who may pin without changing what the wallet
     * posts or what it gets back.
     */
    private function guard(): ?JsonResponse
    {
        if (! config('wallet.ipfs.enabled')) {
            return response()->json([
                'message' => 'Pinning is switched off on this server.',
            ], 503);
        }

        return null;
    }

    /** A node that cannot be reached is this server's failure, hence 502. */
    private function unreachable(\Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'message' => 'The IPFS node did not accept this. Nothing was pinned.',
        ], 502);
    }

    private function maxBytes(): int
    {
        return max(1024, (int) config('wallet.ipfs.max_bytes'));
    }

    /** Laravel sizes uploads in kilobytes; the config is in bytes like everything else. */
    private function maxKilobytes(): int
    {
        return intdiv($this->maxBytes(), 1024);
    }

    /**
     * The filename as it will exist inside IPFS.
     *
     * A name from a browser is untrusted text that ends up in a directory
     * listing, so it keeps only its basename and a conservative character set.
     * It names nothing on this server: the bytes never touch our filesystem.
     */
    private function safeName(?string $name): string
    {
        $base = basename(trim((string) $name));
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? '';
        $clean = trim($clean, '-.');

        return $clean === '' ? 'file' : mb_substr($clean, 0, 80);
    }
}
