<?php

namespace App\Services;

use App\Models\BridgeEvent;
use Illuminate\Http\Request;

class BridgeEventLogger
{
    public const EVENT_TYPES = [
        // Frontend funnel events
        'page_view',
        'direction_selected',
        'evm_wallet_connected',
        'solana_wallet_connected',
        'amount_entered',
        'destination_entered',
        'lock_tx_submitted',
        'lock_tx_confirmed',
        'lock_tx_rejected',
        'bridge_submitted',
        'bridge_submit_failed',
        'tracking_started',

        // Backend events
        'bridge_request_created',
        'relayer_started',
        'relayer_succeeded',
        'relayer_failed',
    ];

    public function log(string $type, array $payload = [], ?Request $request = null): BridgeEvent
    {
        return BridgeEvent::create([
            'session_id' => $payload['session_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'bridge_request_id' => $payload['bridge_request_id'] ?? null,
            'event_type' => $type,
            'direction' => $payload['direction'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'source_address' => $payload['source_address'] ?? null,
            'destination_address' => $payload['destination_address'] ?? null,
            'error_message' => $payload['error_message'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? substr($request->userAgent(), 0, 255) : null,
        ]);
    }
}
