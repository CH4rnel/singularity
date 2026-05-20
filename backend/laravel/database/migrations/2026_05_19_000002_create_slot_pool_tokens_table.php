<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_pool_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_pool_id')->constrained()->cascadeOnDelete();
            $table->string('mint');
            $table->string('token_program')->default('token'); // token | token-2022
            $table->unsignedTinyInteger('decimals');
            $table->string('symbol')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('current_balance')->default('0'); // raw uint64 as string
            $table->boolean('enabled')->default(false);
            $table->string('min_bet')->default('0');
            $table->string('max_bet')->nullable();
            $table->unsignedInteger('weight_override')->nullable();
            $table->timestamps();

            $table->unique(['slot_pool_id', 'mint']);
            $table->index(['slot_pool_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_pool_tokens');
    }
};
