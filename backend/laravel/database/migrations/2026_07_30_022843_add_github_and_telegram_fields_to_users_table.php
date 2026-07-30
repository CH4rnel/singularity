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
        Schema::table('users', function (Blueprint $table) {
            $table->string('github_id')->nullable()->unique()->after('twitter_username');
            $table->string('github_username')->nullable()->after('github_id');
            $table->string('telegram_id')->nullable()->unique()->after('github_username');
            $table->string('telegram_username')->nullable()->after('telegram_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'github_id',
                'github_username',
                'telegram_id',
                'telegram_username',
            ]);
        });
    }
};
