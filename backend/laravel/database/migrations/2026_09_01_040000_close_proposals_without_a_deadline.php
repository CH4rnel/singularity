<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deadlines became the only way a proposal closes (2026_07_03_000001), but a
 * null deadline was still allowed and read as "open" — so seven proposals,
 * the oldest from May, had been open forever with no lever to close them.
 *
 * A vote nobody can end is not a vote. Every remaining null is closed here,
 * at the moment of deploy; from now on `ends_at` is required on create, so no
 * new row can be written without an end. The discussion under a closed
 * proposal stays open, and its author can reopen it by moving the deadline
 * forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('proposals')
            ->whereNull('ends_at')
            ->update(['ends_at' => now()]);
    }

    /**
     * Not reversible: which proposals had no deadline is exactly what this
     * migration spends, and guessing would reopen votes that were closed on
     * purpose.
     */
    public function down(): void {}
};
