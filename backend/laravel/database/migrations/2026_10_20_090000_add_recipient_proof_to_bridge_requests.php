<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            // How the recipient was bound to the deposit (App\Support\BridgeIntent)
            // and when that proof was accepted. Additive: rows written before
            // this column have no proof and are held, never paid on a guess.
            $table->string('recipient_proof', 32)->nullable();
            $table->timestamp('recipient_proven_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn(['recipient_proof', 'recipient_proven_at']);
        });
    }
};
