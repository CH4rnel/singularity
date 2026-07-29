<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user progress on the repeating quests defined in config/gamification.php.
 * Rows are scoped by period_key ("2026-07-29" for daily quests, "2026-W31" for
 * weekly ones), so a fresh board appears every period without any reset job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('quest_key');
            $table->string('period_key');
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('target');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'quest_key', 'period_key']);
            $table->index(['user_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_quests');
    }
};
