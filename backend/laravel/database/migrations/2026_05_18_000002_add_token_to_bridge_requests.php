<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->string('token', 16)->default('CYBER.sol')->after('direction');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropIndex(['token']);
            $table->dropColumn('token');
        });
    }
};
