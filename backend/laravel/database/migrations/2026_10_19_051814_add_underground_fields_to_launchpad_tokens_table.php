<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The underground (underground.cyberia.church) lists every token's
     * homepage the way a 1997 directory did: how many people came by, and
     * when the page last changed. A hit is one address per site per day;
     * `site_updated_at` moves only when the page itself is replaced, not when
     * a logo or a link is.
     */
    public function up(): void
    {
        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('site_hits')->default(0)->after('ipfs_pinned_at');
            $table->timestamp('site_updated_at')->nullable()->after('site_hits');
        });
    }

    public function down(): void
    {
        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->dropColumn(['site_hits', 'site_updated_at']);
        });
    }
};
