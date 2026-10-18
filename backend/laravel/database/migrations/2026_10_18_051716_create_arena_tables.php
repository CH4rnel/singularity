<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_seasons', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('rules');
            $table->string('rules_hash', 64);
            $table->timestamps();
        });
        Schema::create('arena_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_season_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamp('decision_at');
            $table->timestamp('filled_at');
            $table->json('evidence');
            $table->json('result');
            $table->string('payload_hash', 64);
            $table->string('previous_hash', 64)->nullable();
            $table->string('record_hash', 64);
            $table->timestamps();
            $table->unique(['arena_season_id', 'sequence']);
        });
        Schema::create('arena_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('arena_comments')->nullOnDelete();
            $table->text('body')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['arena_season_id', 'id']);
        });
        Schema::create('arena_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['arena_season_id', 'user_id']);
        });
        Schema::create('arena_comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['arena_comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_comment_reports');
        Schema::dropIfExists('arena_followers');
        Schema::dropIfExists('arena_comments');
        Schema::dropIfExists('arena_rounds');
        Schema::dropIfExists('arena_seasons');
    }
};
