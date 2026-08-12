<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Native App Downloads
    |--------------------------------------------------------------------------
    |
    | /download hands out the installers built by .github/workflows/apps.yml and
    | published as a GitHub release on every `app-v*` tag. The release is read
    | through the API so the page can name a version, a date and a size; when the
    | API cannot be reached the buttons still work, because every file also has a
    | permanent address under /releases/latest/download/<file>.
    |
    */

    'repo' => env('APP_DOWNLOADS_REPO', 'cyberia-temple/singularity'),

    // Only releases tagged this way are app builds. The repository is a
    // monorepo; a release cut for anything else is not something to offer as
    // "the wallet".
    'tag_prefix' => env('APP_DOWNLOADS_TAG_PREFIX', 'app-v'),

    // A read-only token is optional. It only raises the API rate limit, which
    // 60 unauthenticated requests an hour rarely hit behind the cache below.
    'token' => env('APP_DOWNLOADS_GITHUB_TOKEN'),

    'cache_ttl' => (int) env('APP_DOWNLOADS_CACHE_TTL', 3600),

    // Kept short: a release is usually published minutes after someone goes
    // looking for it, and a failed lookup must not hide it for an hour.
    'failure_cache_ttl' => (int) env('APP_DOWNLOADS_FAILURE_CACHE_TTL', 300),

    /*
    | The files a release is expected to carry. `id` is what the page keys its
    | copy off, so renaming one is a UI change; `file` must match the artifact
    | names in electron-builder.yml and the workflow, or the download disappears
    | from the page the moment the API confirms the release has no such asset.
    */
    'builds' => [
        ['id' => 'windows-installer', 'platform' => 'windows', 'primary' => true, 'file' => 'Cyberia-Setup-x64.exe'],
        ['id' => 'windows-portable', 'platform' => 'windows', 'primary' => false, 'file' => 'Cyberia-portable-x64.exe'],
        ['id' => 'macos-arm64', 'platform' => 'macos', 'primary' => true, 'file' => 'Cyberia-mac-arm64.dmg'],
        ['id' => 'macos-x64', 'platform' => 'macos', 'primary' => false, 'file' => 'Cyberia-mac-x64.dmg'],
        ['id' => 'linux-appimage', 'platform' => 'linux', 'primary' => true, 'file' => 'Cyberia-linux-x86_64.AppImage'],
        ['id' => 'linux-deb', 'platform' => 'linux', 'primary' => false, 'file' => 'Cyberia-linux-amd64.deb'],
        ['id' => 'android-apk', 'platform' => 'android', 'primary' => true, 'file' => 'Cyberia.apk'],
        // The one build that is not a shell around this site: the extension
        // carries its own vault and signs for dapps in the page.
        ['id' => 'extension-zip', 'platform' => 'extension', 'primary' => true, 'file' => 'Cyberia-extension.zip'],
    ],

    'checksums_file' => 'SHA256SUMS.txt',

];
