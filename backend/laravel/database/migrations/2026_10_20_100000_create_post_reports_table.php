<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reporting a feed post, and the state a reported post ends up in.
     *
     * One row per reporter and post, so reporting twice changes nothing; a post
     * that enough different people reported is hidden (`hidden_at`) rather than
     * deleted, because a person still has to read it and decide.
     */
    public function up(): void
    {
        Schema::create('post_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 32);
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'user_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });

        Schema::dropIfExists('post_reports');
    }
};
