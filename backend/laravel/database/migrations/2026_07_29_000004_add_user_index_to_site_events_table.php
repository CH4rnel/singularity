<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * site_events was only ever queried in aggregate (funnel counts by event or
 * session). Per-user analytics — retention cohorts, "distinct pages today"
 * quests, a member's own activity trail — all scan by user instead, which
 * without this index is a full table scan on the busiest table in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_events', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('site_events', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
