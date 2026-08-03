<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Native App Shells
    |--------------------------------------------------------------------------
    |
    | The Android (frontend/mobile) and desktop (frontend/desktop) shells render
    | this site. Deep links only open in the installed app once the site
    | advertises the app identity below, so both files 404 until configured.
    |
    */

    'android' => [
        'package' => env('APP_ANDROID_PACKAGE', 'church.cyberia.app'),

        // SHA-256 of the signing certificate, colon separated. List both the
        // upload key and the Play app-signing key, comma separated.
        // keytool -list -v -keystore <keystore> -alias <alias>
        'fingerprints' => env('APP_ANDROID_SHA256_FINGERPRINT', ''),
    ],

    'ios' => [
        // "<TEAM ID>.church.cyberia.app"
        'app_id' => env('APP_IOS_APP_ID', ''),
    ],

];
