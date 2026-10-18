<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily reminders through the Telegram bot.
 *
 * Both tables are shared with `services/telegram-bot`, which creates them too
 * (`CREATE TABLE IF NOT EXISTS`, same shape) because either side may start
 * first. The bot owns the opt-ins — only someone who said /remind is written
 * to — and drains the outbox; this side decides who gets what, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tg_reminder_optins')) {
            Schema::create('tg_reminder_optins', function (Blueprint $table) {
                $table->unsignedBigInteger('tg_user_id')->primary();
                $table->boolean('enabled')->default(true);
                $table->string('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('tg_outbox')) {
            Schema::create('tg_outbox', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tg_user_id')->index();
                $table->string('kind');
                $table->text('text');
                $table->string('url')->nullable();
                $table->string('created_at')->nullable();
                $table->string('sent_at')->nullable();
                $table->string('error')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_outbox');
        Schema::dropIfExists('tg_reminder_optins');
    }
};
