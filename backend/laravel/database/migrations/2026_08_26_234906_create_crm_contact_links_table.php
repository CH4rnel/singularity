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
        Schema::create('crm_contact_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_contact_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('kind', 32)->default('link');
            $table->string('url', 2048);
            $table->timestamps();

            $table->unique(['crm_contact_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_contact_links');
    }
};
