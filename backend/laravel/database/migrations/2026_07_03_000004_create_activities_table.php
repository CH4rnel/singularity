<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DAO activity stream, written in-request when a proposal is created, a vote
 * is cast or a comment is posted. Serves the /dao feed, profile activity and
 * the "participants of this DAO" recipient index for notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dao_id')->nullable()->constrained()->cascadeOnDelete();
            $table->morphs('subject');
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['dao_id', 'id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
