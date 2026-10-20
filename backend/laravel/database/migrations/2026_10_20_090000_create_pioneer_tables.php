<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pioneer Season: one row per checkpoint a person passed, and one per person
 * who passed them all.
 *
 * Two unique indexes carry the rules. (season, user, checkpoint) makes every
 * checkpoint once per profile however many wallets it holds; (season,
 * checkpoint, reference) makes one transaction or bridge request credit one
 * profile, so a transfer between two accounts cannot pay both of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pioneer_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('season', 16);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('checkpoint');
            $table->string('reference');
            $table->timestamp('qualified_at');
            $table->timestamps();

            $table->unique(['season', 'user_id', 'checkpoint']);
            $table->unique(['season', 'checkpoint', 'reference']);
        });

        Schema::create('pioneer_finishers', function (Blueprint $table) {
            $table->id();
            $table->string('season', 16);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->string('status', 16)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['season', 'user_id']);
            $table->index(['season', 'status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pioneer_finishers');
        Schema::dropIfExists('pioneer_checkpoints');
    }
};
