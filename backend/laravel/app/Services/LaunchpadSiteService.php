<?php

namespace App\Services;

use App\Models\LaunchpadToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publishes a token's uploaded page to IPFS.
 *
 * The copy this app serves on `<subdomain>.cyberia.church` is a mirror: it
 * lives as long as the DNS name and this host do. The pinned copy is the one
 * that outlives both, so every upload is pinned and the CID is what the API
 * hands out as the site's permanent address.
 *
 * Pinning is deliberately best-effort. A token creator uploading a page must
 * not get an error because the node behind `ipfs.api_url` is restarting — the
 * page is stored either way and `launchpad:pin-sites` pins whatever is still
 * missing a CID.
 */
class LaunchpadSiteService
{
    public function __construct(private readonly IpfsService $ipfs) {}

    /** Pin the token's current page, record the CID, and return it (null if it could not be pinned). */
    public function publish(LaunchpadToken $token): ?string
    {
        if (! $token->html_path || ! Storage::disk('public')->exists($token->html_path)) {
            return null;
        }

        try {
            // Wrapped in a directory and named index.html so a gateway renders
            // the CID as a page rather than offering it as a file.
            $cid = $this->ipfs->add(
                (string) Storage::disk('public')->get($token->html_path),
                'index.html',
                'text/html',
                wrapWithDirectory: true,
            );
        } catch (\Throwable $e) {
            Log::warning('Launchpad token site could not be pinned to IPFS', [
                'chain_id' => $token->chain_id,
                'address' => $token->address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $token->forceFill([
            'ipfs_cid' => $cid,
            'ipfs_pinned_at' => now(),
        ])->save();

        return $cid;
    }
}
