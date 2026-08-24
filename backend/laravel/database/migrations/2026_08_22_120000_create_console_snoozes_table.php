<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not now" as a first-class answer.
 *
 * Half of what the console shows a duty operator is not "do this" but "do not
 * show me this until nine". Without somewhere to write that down the only way
 * to quiet a row is to fix it or to learn to ignore the whole list, and the
 * second one is what actually happens.
 *
 * A snooze is keyed on the feed item, not on a database row: `incident:12`,
 * `task:45`, `gas:tank`. Items come from six different sources and some of
 * them (a low tank, a retention drop) have no row anywhere to hang a column
 * on. The key is stable across sweeps, which is the only property that
 * matters — the same tank does not come back as a new item every five
 * minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_snoozes', function (Blueprint $table) {
            $table->id();
            // The feed item's key. Unique: snoozing twice is one snooze, and
            // the second one only moves the wake-up time.
            $table->string('key')->unique();
            $table->timestamp('snoozed_until');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_snoozes');
    }
};
