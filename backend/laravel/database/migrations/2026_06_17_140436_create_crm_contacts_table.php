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
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('telegram')->nullable();
            $table->string('evm_address')->nullable();
            $table->string('solana_address')->nullable();

            // Categorisation: lead | holder | whale
            $table->string('type')->default('lead');
            // Pipeline stage: new | contacted | qualified | customer | lost
            $table->string('status')->default('new');
            // Where the contact came from: manual | platform | bridge | whale_bot
            $table->string('source')->default('manual');

            // Optional link to a platform user (web3 or email account).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Cached on-chain balances refreshed by the sync service.
            $table->decimal('cyber_balance', 36, 18)->nullable();
            $table->decimal('cyber_sol_balance', 36, 6)->nullable();

            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
            $table->index('evm_address');
            $table->index('solana_address');
            $table->index('type');
            $table->index('status');
            $table->index('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
