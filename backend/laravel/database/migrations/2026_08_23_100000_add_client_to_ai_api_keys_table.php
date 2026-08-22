<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_api_keys', function (Blueprint $table) {
            $table->string('client', 24)->default('api')->index()->after('name');
            $table->uuid('instance_id')->nullable()->index()->after('client');
        });
    }

    public function down(): void
    {
        Schema::table('ai_api_keys', function (Blueprint $table) {
            $table->dropIndex(['client']);
            $table->dropIndex(['instance_id']);
            $table->dropColumn(['client', 'instance_id']);
        });
    }
};
