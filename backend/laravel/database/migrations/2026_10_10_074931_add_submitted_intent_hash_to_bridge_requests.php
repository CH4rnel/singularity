<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->char('submitted_intent_hash', 64)->nullable();
            $table->string('submitted_source_tx_hash')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropUnique(['submitted_source_tx_hash']);
            $table->dropColumn(['submitted_intent_hash', 'submitted_source_tx_hash']);
        });
    }
};
