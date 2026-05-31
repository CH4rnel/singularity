<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bridge_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('direction')->nullable();
            $table->decimal('amount', 36, 18)->nullable();
            $table->string('source_address')->nullable();
            $table->string('destination_address')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_events');
    }
};
