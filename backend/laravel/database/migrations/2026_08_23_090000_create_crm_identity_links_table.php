<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "These two are the same person."
 *
 * One person arrives here under several names and leaves several records. The
 * case that forced this: a visitor signed into the site with a Solana wallet
 * (`users` #38, contact #284), bridged from an EVM address (contact #285), and
 * the console showed two strangers — while `bridge_requests` #68 held the
 * account id, the EVM sender and the Solana recipient in a single row. The
 * evidence was already in the database; nothing was reading it.
 *
 * **A graph, not a merge.** Merging is destructive and one-way: it picks a
 * survivor, rewrites foreign keys, and the moment it is wrong there is nothing
 * to undo it with. An edge asserts only what it says — that two identities
 * belong to one person — leaves both records intact, carries the evidence that
 * justified it, and is deleted by the same click that made it. A "person" is
 * then a connected component, computed on read, and re-deriving the graph is
 * always safe.
 *
 * **Identities, not records.** The endpoints are things a person *is* — an
 * account, an EVM address, a Solana address — never a `crm_contacts` row,
 * which is a record *about* them and gets recreated by the sync. Contacts join
 * the graph through the addresses they carry, so a contact deleted and rebuilt
 * lands back in the same component.
 *
 * Analytics installations are deliberately absent. `analytics_addresses`
 * exists to verify funding and price a sponsored drip, and using it as an
 * identity index would be a third purpose the wallet's privacy notice does not
 * claim — see docs/product-analytics.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_identity_links', function (Blueprint $table) {
            $table->id();

            /*
             * The two endpoints, stored in a canonical order (`kind:value`
             * ascending) so an edge cannot be inserted twice by being asserted
             * from the other end. Kinds: user | evm | solana.
             */
            $table->string('left_kind', 16);
            $table->string('left_value', 128);
            $table->string('right_kind', 16);
            $table->string('right_value', 128);

            /*
             * Where the claim came from. `bridge_sender` and `account` are
             * signed or authenticated facts; `bridge_recipient` is a guess,
             * because a person may perfectly well bridge to a friend; `manual`
             * is an operator who looked at the two records and decided.
             */
            $table->string('source', 24);

            /*
             * Whether this edge is allowed to join a person on its own.
             * `strong` links; `weak` is offered as a suggestion and waits for
             * somebody to confirm it. A guess that silently merged two
             * customers would be worse than two records for one customer.
             */
            $table->string('confidence', 8)->default('strong');

            // What to point at when asked why. A row reference, never prose.
            $table->string('evidence', 191)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(
                ['left_kind', 'left_value', 'right_kind', 'right_value'],
                'crm_identity_links_edge_unique',
            );
            $table->index(['left_kind', 'left_value']);
            $table->index(['right_kind', 'right_value']);
            $table->index('confidence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_identity_links');
    }
};
