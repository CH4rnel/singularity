<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language to write to an installation in.
 *
 * Same reasoning as the column on `users`: the interface picks its language in
 * the browser and never tells Laravel, because a rendered page has a person in
 * front of it. A notification is composed by a scheduled command with no
 * browser to ask, so the one moment the browser is already registering
 * something durable — the push subscription — is where it says so.
 *
 * It is not a tracking property and is deliberately outside the analytics
 * taxonomy's allowlist: nothing reads it except the text of a notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_users', function (Blueprint $table) {
            $table->string('notification_locale', 12)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_users', function (Blueprint $table) {
            $table->dropColumn('notification_locale');
        });
    }
};
