<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_metadata_cache', function (Blueprint $table) {
            $table->string('mint')->primary();
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();
            $table->string('logo_url')->nullable();
            $table->unsignedTinyInteger('decimals')->nullable();
            $table->string('token_program')->nullable(); // token | token-2022
            $table->boolean('has_freeze_authority')->default(false);
            $table->json('raw')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_metadata_cache');
    }
};
