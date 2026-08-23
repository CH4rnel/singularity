<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where this person is on X.
     *
     * The record already carries the two addresses and the Telegram handle,
     * and those are how the console reaches people who arrived through the
     * chain. Everybody found on X is reachable nowhere else — an address is
     * not a person you can write to — so the handle is a column rather than a
     * tag or a note: the row's one action is "write to them", and it needs
     * somewhere to point.
     *
     * Stored bare (`lain`, never `@lain` and never the URL), because the link
     * is built where it is rendered and a stored URL rots into three shapes
     * of the same handle.
     */
    public function up(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->string('x_handle')->nullable()->after('telegram');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropColumn('x_handle');
        });
    }
};
