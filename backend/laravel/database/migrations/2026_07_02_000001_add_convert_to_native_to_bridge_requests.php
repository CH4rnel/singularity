<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            // User asked the relayer to auto-convert bridged CYBER.sol into
            // native CYBER (burning the CYBER.sol through CyberSolBurnSwap).
            $table->boolean('convert_to_native')->default(false)->after('gas_drop_amount');
            // Actual outcome: null until relayed, true when converted, false
            // when the relayer fell back to a plain CYBER.sol delivery.
            $table->boolean('converted')->nullable()->after('convert_to_native');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn(['convert_to_native', 'converted']);
        });
    }
};
