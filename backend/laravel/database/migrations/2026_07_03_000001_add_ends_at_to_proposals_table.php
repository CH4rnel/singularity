<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proposals move from a hand-toggled open/closed status column to a voting
 * deadline. Status is now computed at read time (Proposal::status accessor):
 * open while ends_at is null or in the future — no cron needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->timestamp('ends_at')->nullable()->index()->after('description');
        });

        // Closed proposals keep their closure moment; open ones stay open.
        DB::table('proposals')
            ->where('status', 'closed')
            ->update(['ends_at' => DB::raw('updated_at')]);

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('status')->default('open');
        });

        DB::table('proposals')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update(['status' => 'closed']);

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });
    }
};
