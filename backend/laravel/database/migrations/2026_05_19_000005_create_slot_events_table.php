<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_spin_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_events');
    }
};
