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
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_contact_id')->constrained()->cascadeOnDelete();
            // Author of the note; null once the user is deleted or for system entries.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // note | status_change | sync | activity
            $table->string('type')->default('note');
            $table->text('body');
            $table->timestamps();

            $table->index('crm_contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_notes');
    }
};
