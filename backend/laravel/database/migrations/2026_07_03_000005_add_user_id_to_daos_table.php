<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DAO creator/owner — ownership gates update/delete (DaoPolicy). Existing
 * rows keep a null owner (nobody can edit them except via DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daos', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
