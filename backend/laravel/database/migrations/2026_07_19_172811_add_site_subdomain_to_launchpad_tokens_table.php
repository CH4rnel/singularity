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
        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->string('site_subdomain', 63)->nullable()->unique()->after('html_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->dropUnique(['site_subdomain']);
            $table->dropColumn('site_subdomain');
        });
    }
};
