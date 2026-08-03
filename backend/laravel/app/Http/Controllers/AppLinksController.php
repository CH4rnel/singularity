<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the association files that let https://cyberia.church links open in
 * the installed native shells instead of a browser tab.
 *
 * Both files are unsigned public metadata; they only mean anything once the app
 * identities are configured (see config/native.php).
 */
class AppLinksController extends Controller
{
    /** Android App Links — https://developer.android.com/training/app-links/verify-android-applinks */
    public function assetlinks(): JsonResponse
    {
        $fingerprints = $this->fingerprints();

        if ($fingerprints === []) {
            throw new NotFoundHttpException;
        }

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => (string) config('native.android.package'),
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]], options: JSON_UNESCAPED_SLASHES);
    }

    /** iOS Universal Links — https://developer.apple.com/documentation/xcode/supporting-associated-domains */
    public function appleAppSiteAssociation(): JsonResponse
    {
        $appId = trim((string) config('native.ios.app_id'));

        if ($appId === '') {
            throw new NotFoundHttpException;
        }

        return response()->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => [$appId],
                    'components' => [['/' => '*']],
                ]],
            ],
            'webcredentials' => [
                'apps' => [$appId],
            ],
        ], options: JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private function fingerprints(): array
    {
        $configured = explode(',', (string) config('native.android.fingerprints'));

        return array_values(array_filter(array_map(
            static fn (string $fingerprint): string => strtoupper(trim($fingerprint)),
            $configured,
        )));
    }
}
