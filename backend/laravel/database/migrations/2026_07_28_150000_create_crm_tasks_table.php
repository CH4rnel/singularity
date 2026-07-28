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
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            // Tasks usually hang off a contact ("call this whale back"), but a
            // null contact is allowed for standalone operator chores.
            $table->foreignId('crm_contact_id')->nullable()->constrained()->cascadeOnDelete();
            // Operator the task is assigned to; null means unassigned. Kept as
            // a plain users FK so a deleted account releases its tasks.
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            // open | in_progress | done | cancelled
            $table->string('status')->default('open');
            // low | normal | high
            $table->string('priority')->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index('assigned_to_user_id');
            $table->index('crm_contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_tasks');
    }
};
