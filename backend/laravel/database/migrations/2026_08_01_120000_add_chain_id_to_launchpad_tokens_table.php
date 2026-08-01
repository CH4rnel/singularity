<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A token can now be launched on several chains at once, so metadata is
     * keyed by (chain_id, address) instead of the address alone — two chains
     * can hand out the same contract address. SQLite cannot swap a primary
     * key in place, so the table is rebuilt and copied over with every
     * existing row attributed to Cyberia.
     */
    public function up(): void
    {
        Schema::create('launchpad_tokens_multichain', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chain_id')->default(49406);
            $table->string('address', 42);
            $table->string('creator', 42)->nullable();
            $table->string('name')->nullable();
            $table->string('symbol', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('html_path')->nullable();
            $table->string('site_subdomain', 63)->nullable();
            $table->timestamps();
        });

        foreach (DB::table('launchpad_tokens')->orderBy('address')->cursor() as $row) {
            DB::table('launchpad_tokens_multichain')->insert([
                'chain_id' => 49406,
                'address' => $row->address,
                'creator' => $row->creator,
                'name' => $row->name,
                'symbol' => $row->symbol,
                'description' => $row->description,
                'image_path' => $row->image_path,
                'html_path' => $row->html_path,
                'site_subdomain' => $row->site_subdomain,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        // Indexes are created only after the old table (and its identically
        // named indexes) is gone: SQLite index names are database-global.
        Schema::drop('launchpad_tokens');
        Schema::rename('launchpad_tokens_multichain', 'launchpad_tokens');

        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->unique(['chain_id', 'address'], 'launchpad_tokens_chain_id_address_unique');
            $table->unique('site_subdomain', 'launchpad_tokens_site_subdomain_unique');
        });
    }

    public function down(): void
    {
        Schema::create('launchpad_tokens_single', function (Blueprint $table) {
            $table->string('address', 42)->primary();
            $table->string('creator', 42)->nullable();
            $table->string('name')->nullable();
            $table->string('symbol', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('html_path')->nullable();
            $table->string('site_subdomain', 63)->nullable();
            $table->timestamps();
        });

        // Only one row per address survives: the pre-multichain schema cannot
        // hold the same address on two chains.
        $seen = [];

        foreach (DB::table('launchpad_tokens')->orderBy('id')->cursor() as $row) {
            if (isset($seen[$row->address])) {
                continue;
            }

            $seen[$row->address] = true;
            DB::table('launchpad_tokens_single')->insert([
                'address' => $row->address,
                'creator' => $row->creator,
                'name' => $row->name,
                'symbol' => $row->symbol,
                'description' => $row->description,
                'image_path' => $row->image_path,
                'html_path' => $row->html_path,
                'site_subdomain' => $row->site_subdomain,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('launchpad_tokens');
        Schema::rename('launchpad_tokens_single', 'launchpad_tokens');

        Schema::table('launchpad_tokens', function (Blueprint $table) {
            $table->unique('site_subdomain', 'launchpad_tokens_site_subdomain_unique');
        });
    }
};
