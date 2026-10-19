<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the chat relay's numbering back ahead of every wallet's cursor.
 *
 * A wallet asks for "everything after the last id I saw", so the relay's ids
 * must only ever grow. The migration that added plain messages changed a
 * column, and on SQLite a column change rebuilds the table — which restarts
 * AUTOINCREMENT from the highest id still in it. Old envelopes had already
 * been pruned, so new messages were numbered from 1 again, and every wallet
 * that had used the chat before was asking for ids above the ones it was now
 * being sent. Its new mail was there and it never asked for it.
 *
 * So the rows numbered since then move above anything a cursor could hold, and
 * the sequence follows them. Wallets that already stored a moved row under its
 * old number recognise it by its message id (`storeChatRows`), so nothing is
 * shown twice. Other databases never restarted their numbering; only SQLite
 * tables with rows below the floor are touched.
 */
return new class extends Migration
{
    /**
     * Above any id the relay handed out before the rebuild. A handful of
     * wallets had used the chat, and its numbering had reached the teens.
     */
    private const FLOOR = 10_000;

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $low = DB::table('wallet_chat_messages')->where('id', '<', self::FLOOR)->count();

        if ($low === 0) {
            return;
        }

        DB::transaction(function (): void {
            DB::update('update wallet_chat_messages set id = id + ? where id < ?', [self::FLOOR, self::FLOOR]);

            $highest = (int) DB::table('wallet_chat_messages')->max('id');

            DB::delete("delete from sqlite_sequence where name = 'wallet_chat_messages'");
            DB::insert("insert into sqlite_sequence (name, seq) values ('wallet_chat_messages', ?)", [$highest]);
        });
    }

    public function down(): void
    {
        // Ids only move forward: renumbering back down would hide mail again.
    }
};
