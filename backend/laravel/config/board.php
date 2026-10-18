<?php

return [
    'boards' => ['general', 'arena', 'build'],
    'rpc_url' => env('BOARD_RPC_URL', 'https://rpc.cyberia.church'),
    'confirmations' => 2,
    'storage_bytes' => 250_000_000,
    'images_per_day' => 10,
    'lain_user_id' => (int) env('BOARD_LAIN_USER_ID', 0),
];
