<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lain_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 'user' | 'lain' | 'reset'. A reset row is a context boundary: the
            // model only sees messages after the latest one, but every row stays
            // in the table so conversations remain analyzable later.
            $table->string('role', 8);
            $table->text('content');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lain_chat_messages');
    }
};
