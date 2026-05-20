<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slot_pool_tokens', function (Blueprint $table) {
            $table->string('source')->default('admin')->after('weight_override'); // admin | pumpfun_bulk | pumpfun_lazy
            $table->decimal('pumpfun_market_cap_usd', 20, 2)->nullable()->after('source');
            $table->timestamp('pumpfun_last_seen_at')->nullable()->after('pumpfun_market_cap_usd');
        });
    }

    public function down(): void
    {
        Schema::table('slot_pool_tokens', function (Blueprint $table) {
            $table->dropColumn(['source', 'pumpfun_market_cap_usd', 'pumpfun_last_seen_at']);
        });
    }
};
