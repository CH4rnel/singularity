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
        Schema::create('board_threads', function (Blueprint $table) {
            $table->id();
            $table->string('board', 32);
            $table->string('title', 160);
            $table->boolean('closed')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('bumped_at');
            $table->timestamps();
            $table->index(['board', 'hidden_at', 'bumped_at']);
        });
        Schema::create('board_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('board_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('board_posts')->nullOnDelete();
            $table->string('author', 80);
            $table->string('actor', 16)->default('human');
            $table->string('author_address', 42)->nullable();
            $table->text('body');
            $table->string('image_path')->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->unsignedInteger('image_bytes')->default(0);
            $table->string('content_hash', 64);
            $table->string('anchor_tx', 66)->nullable()->unique();
            $table->unsignedBigInteger('anchor_block')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['board_thread_id', 'id']);
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('board_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['board_post_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_reports');
        Schema::dropIfExists('board_posts');
        Schema::dropIfExists('board_threads');
    }
};
