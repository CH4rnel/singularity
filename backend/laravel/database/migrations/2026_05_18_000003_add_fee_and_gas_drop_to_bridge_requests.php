<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->decimal('fee_amount', 36, 18)->nullable()->after('amount');
            $table->decimal('fee_usd', 20, 8)->nullable()->after('fee_amount');
            $table->boolean('gas_drop_planned')->default(false)->after('fee_usd');
            $table->decimal('gas_drop_amount', 36, 18)->nullable()->after('gas_drop_planned');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn(['fee_amount', 'fee_usd', 'gas_drop_planned', 'gas_drop_amount']);
        });
    }
};
