<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One level of comment threading: replies point at a top-level parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_comments', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('user_id')
                ->constrained('proposal_comments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
