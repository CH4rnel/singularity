<?php

namespace App\Services\Slots;

use App\Models\SlotEvent;
use App\Models\SlotSpin;

class SlotEventLogger
{
    public function record(string $eventType, ?SlotSpin $spin = null, array $metadata = [], ?string $error = null): void
    {
        SlotEvent::create([
            'slot_spin_id' => $spin?->id,
            'event_type' => $eventType,
            'error_message' => $error,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
