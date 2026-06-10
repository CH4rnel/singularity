<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BridgeEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BridgeEventController extends Controller
{
    public function __construct(
        private BridgeEventLogger $logger
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string', Rule::in(BridgeEventLogger::EVENT_TYPES)],
            'direction' => ['nullable', 'in:sol_to_evm,evm_to_sol'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'source_address' => ['nullable', 'string', 'max:255'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'error_message' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
            'bridge_request_id' => ['nullable', 'integer'],
        ]);

        $validated['user_id'] = $request->user()?->id;

        $this->logger->log($validated['event_type'], $validated, $request);

        return response()->json(['ok' => true], 202);
    }
}
