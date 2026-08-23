<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Чат" — one room for the operators, and the file dump is the same room.
 *
 * There is deliberately no folder table and no channel table. A folder is a
 * place where a file is put silently; a month later it holds five files named
 * final2.log and nobody remembers what any of them was for. Here a file
 * cannot exist without the message that brought it, so it always carries who
 * brought it and why, and the "Files" lens is that stream read a second way.
 *
 * Channels are absent for the same reason a task board has no swimlanes here:
 * there are three operators. What separates one conversation from another is
 * the object a message is attached to — a person, a task, a machine — not a
 * room someone had to create first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_chat_messages', function (Blueprint $table) {
            $table->id();
            // Null is LainOS: it answers in the room but holds no account,
            // and an account for a daemon would be an account someone could
            // sign in as.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author', 16)->default('operator');
            $table->text('body')->nullable();

            // Whether this line called LainOS, and what came of the call.
            // `awaiting` is a real state and not an error: nobody has run the
            // request yet, and any operator may run it from the room.
            $table->boolean('calls_lainos')->default(false);
            $table->string('lainos_state', 16)->nullable();
            $table->string('lainos_note')->nullable();

            // What the line was about. Both are resolved from the text the
            // operator typed (`#name`), so attaching costs no extra field.
            $table->foreignId('crm_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crm_task_id')->nullable()->constrained('crm_tasks')->nullOnDelete();

            // For a LainOS answer: which backend replied and what it was
            // given. The room prints this under the answer, because "LainOS"
            // is two different correspondents and the difference matters.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['calls_lainos', 'lainos_state']);
        });

        Schema::create('crm_chat_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_chat_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // A path on the private disk. There is no public URL for these:
            // the console is a 404 for everyone who is not an operator, and a
            // file served from /storage would be the one door left open.
            $table->string('path');
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            // The segment this file falls into (images, logs, documents…),
            // decided once on upload so the lens never re-guesses.
            $table->string('kind', 16)->default('other');
            $table->timestamps();

            $table->index('created_at');
            $table->index('kind');
        });

        Schema::create('crm_chat_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_chat_reads');
        Schema::dropIfExists('crm_chat_files');
        Schema::dropIfExists('crm_chat_messages');
    }
};
