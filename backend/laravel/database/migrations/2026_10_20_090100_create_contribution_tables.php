<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The open-source reward engine (config/contributions.php).
 *
 * Five tables in the order the money flows: a repository that pays, a GitHub
 * account that contributed, a contribution with the verdict on it, an epoch
 * that split one pool, and the leaf each contributor claims on chain.
 *
 * Contributions are keyed by GitHub's numeric account id and not by a user:
 * the work exists before anybody links a wallet to it, and linking later must
 * find it waiting rather than lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_repositories', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->unique();
            $table->decimal('weight', 6, 3)->default(1);
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('contributor_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('github_id')->unique();
            $table->string('login');
            $table->string('type')->default('User');
            $table->timestamp('github_created_at')->nullable();
            $table->unsignedInteger('followers')->nullable();
            $table->unsignedInteger('public_repos')->nullable();
            $table->timestamp('fetched_at')->nullable();
            // Set by an operator: every contribution of this account is refused.
            $table->timestamp('banned_at')->nullable();
            $table->string('ban_reason')->nullable();
            $table->timestamps();

            $table->index('login');
        });

        Schema::create('contribution_epochs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('asset');
            $table->unsignedTinyInteger('decimals');
            // Raw units in the asset's own decimals, as strings: wei overflow 64 bits.
            $table->string('pool_raw');
            $table->string('allocated_raw')->default('0');
            $table->unsignedBigInteger('total_points')->default(0);
            $table->string('merkle_root', 66)->nullable();
            // built | published
            $table->string('status');
            $table->string('publish_tx', 66)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_repository_id')->constrained()->cascadeOnDelete();
            $table->string('github_id');
            $table->string('login');
            // pull_request | documentation | issue
            $table->string('kind');
            // GitHub's own number in that repository; with the repository and
            // the kind it is the contribution's identity, so a re-sync is a no-op.
            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('url');
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('commits')->default(0);
            $table->string('merged_by')->nullable();
            $table->timestamp('accepted_at');
            // Hundredths of a point, so the arithmetic stays integer.
            $table->unsignedInteger('points')->default(0);
            // counted | rejected
            $table->string('status');
            $table->string('reason')->nullable();
            // An operator overrode the guard; the sync never changes it back.
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('contribution_epoch_id')->nullable()->constrained('contribution_epochs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contribution_repository_id', 'kind', 'number']);
            $table->index(['github_id', 'accepted_at']);
            $table->index(['status', 'accepted_at']);
        });

        Schema::create('contribution_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_epoch_id')->constrained('contribution_epochs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('github_id');
            $table->string('address', 42);
            $table->unsignedBigInteger('points');
            $table->string('amount_raw');
            $table->string('leaf', 66);
            $table->json('proof');
            $table->timestamp('claimed_at')->nullable();
            $table->string('claim_tx', 66)->nullable();
            $table->timestamps();

            $table->unique(['contribution_epoch_id', 'address']);
            $table->index('address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_rewards');
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('contribution_epochs');
        Schema::dropIfExists('contributor_accounts');
        Schema::dropIfExists('contribution_repositories');
    }
};
