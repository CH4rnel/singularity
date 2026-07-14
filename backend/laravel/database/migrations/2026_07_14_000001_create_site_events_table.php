<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Site-wide user-action funnel (visits → wallet → swap/LP), the
        // cross-page sibling of bridge_events. Written by POST /api/events.
        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wallet_address')->nullable();
            $table->string('event')->index();
            $table->string('page')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['event', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_events');
    }
};
