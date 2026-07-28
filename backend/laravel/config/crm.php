<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EVM wallets allowed to access the CRM (/crm and everything under it).
    |--------------------------------------------------------------------------
    | Comma-separated list of 0x addresses. Matching is case-insensitive; the
    | users table stores wallet_address lowercased. Anyone else gets a 404, so
    | the CRM is not discoverable by ordinary authenticated users.
    */

    'admin_wallets' => array_values(array_filter(array_map(
        fn (string $address) => strtolower(trim($address)),
        explode(',', (string) env('CRM_ADMIN_WALLETS', implode(',', [
            '0xafF26832db3557daF540B0B09DeE06C24B8A38BB',
            '0x6f4AFc4F18Bd72a92D1c0087ea5fB79754652405',
        ]))),
    ))),

];
