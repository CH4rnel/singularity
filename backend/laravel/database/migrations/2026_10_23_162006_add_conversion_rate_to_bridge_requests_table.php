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
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->string('conversion_numerator', 36)->nullable();
            $table->string('conversion_denominator', 36)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn(['conversion_numerator', 'conversion_denominator']);
        });
    }
};
