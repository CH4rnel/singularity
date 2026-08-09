<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A token site's canonical address is its CID, not a path on this host.
     * The row remembers which CID the currently uploaded page pinned to, and
     * when — `launchpad:pin-sites` uses the null case to catch up on pages
     * uploaded while the IPFS node was down.
     */
    public function up(): void
    {
        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->string('ipfs_cid', 100)->nullable()->after('site_subdomain');
            $table->timestamp('ipfs_pinned_at')->nullable()->after('ipfs_cid');
        });
    }

    public function down(): void
    {
        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->dropColumn(['ipfs_cid', 'ipfs_pinned_at']);
        });
    }
};
