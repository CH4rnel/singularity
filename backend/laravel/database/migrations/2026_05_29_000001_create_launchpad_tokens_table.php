<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launchpad_tokens', function (Blueprint $table) {
            $table->string('address', 42)->primary();
            $table->string('creator', 42)->nullable();
            $table->string('name')->nullable();
            $table->string('symbol', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launchpad_tokens');
    }
};
