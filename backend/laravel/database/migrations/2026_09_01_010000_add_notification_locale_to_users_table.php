<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one language fact Laravel is allowed to keep.
 *
 * Interface language is deliberately a browser decision (`useLocale.ts`) and
 * never the server's — a page is rendered with a person in front of it. A push
 * notification is the exact opposite: it is composed hours later, by a
 * scheduled command, with no browser anywhere to ask. So the browser tells us
 * once, at the only moment it is already talking to us about something durable
 * — when it registers a push subscription — and that is the whole exception.
 *
 * Null means "nobody has said", and the notification falls back to English
 * exactly like `t()` does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('notification_locale', 12)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_locale');
        });
    }
};
