<?php

namespace App\Services\Tracker;

use App\Models\TrackerPeer;
use App\Models\TrackerRelease;
use App\Support\Bencode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The tracker's swarm bookkeeping.
 *
 * A tracker is a smaller thing than it sounds: it takes "I am at this address,
 * I have this much" from a client every quarter of an hour and hands back some
 * addresses of clients that said the same recently. It never sees a file, and
 * a peer that stops talking is deleted rather than remembered, because the
 * only thing a list of dead peers does is send new joiners to nobody.
 *
 * The one policy here is that a swarm has to be a **release**: an announce for
 * an info hash nobody minted is refused. An open tracker is a stranger's
 * infrastructure by lunchtime, and the whole point of this one is that
 * everything on it can be traced back to a token that says who put it there.
 */
final class TrackerAnnounceService
{
    /**
     * Answer one announce, bencoded and ready to send.
     *
     * @throws TrackerFailure
     */
    public function announce(AnnounceRequest $request): string
    {
        $release = TrackerRelease::query()
            ->listed()
            ->where('info_hash', $request->infoHash)
            ->first();

        if ($release === null) {
            throw new TrackerFailure(
                'this tracker only announces releases minted as NFTs — register the torrent first',
            );
        }

        $interval = (int) config('tracker.interval', 900);

        // Every announce sweeps this one swarm. It costs a single indexed
        // delete and it means the counts below are of peers that are actually
        // there, without depending on a scheduled command having run.
        TrackerPeer::query()
            ->where('info_hash', $request->infoHash)
            ->where('expires_at', '<', now())
            ->delete();

        if ($request->event === 'stopped') {
            TrackerPeer::query()
                ->where('info_hash', $request->infoHash)
                ->where('peer_id', $request->peerId)
                ->delete();
        } else {
            $this->remember($request, $interval);
        }

        if ($request->event === 'completed') {
            $release->increment('completed');
        }

        $seeders = TrackerPeer::query()
            ->where('info_hash', $request->infoHash)
            ->where('seeder', true)
            ->count();

        $leechers = TrackerPeer::query()
            ->where('info_hash', $request->infoHash)
            ->where('seeder', false)
            ->count();

        $release->forceFill([
            'seeders' => $seeders,
            'leechers' => $leechers,
            'last_announce_at' => now(),
        ])->save();

        $peers = $request->event === 'stopped'
            ? []
            : $this->pick($request);

        $response = [
            'interval' => $interval,
            'min interval' => (int) config('tracker.min_interval', 300),
            'complete' => $seeders,
            'incomplete' => $leechers,
            'downloaded' => (int) $release->completed,
        ];

        return Bencode::encode($response + $this->peerFields($peers, $request->compact));
    }

    /**
     * Scrape: how big is each of these swarms.
     *
     * With no hashes named this answers for the whole index rather than
     * refusing — the list is public and already on a web page, and a client
     * that asks once for everything is cheaper than twenty announces.
     *
     * @param  list<string>  $hexHashes
     */
    public function scrape(array $hexHashes): string
    {
        $query = TrackerRelease::query()->listed();

        $releases = $hexHashes === []
            ? $query->orderByDesc('seeders')->limit(100)->get()
            : $query->whereIn('info_hash', array_slice($hexHashes, 0, 100))->get();

        $files = [];

        foreach ($releases as $release) {
            // Keys in a scrape dictionary are the raw twenty bytes, not hex:
            // this is the one place the wire format surfaces again.
            $files[hex2bin($release->info_hash)] = [
                'complete' => (int) $release->seeders,
                'downloaded' => (int) $release->completed,
                'incomplete' => (int) $release->leechers,
                'name' => $release->name,
            ];
        }

        return Bencode::encode(['files' => $files]);
    }

    /** A refusal, in the only vocabulary a client understands. */
    public function failure(string $reason): string
    {
        return Bencode::encode(['failure reason' => $reason]);
    }

    /**
     * This peer, as of now.
     *
     * An upsert rather than a read-then-write: two announces from the same
     * client arriving together is ordinary, and the unique key on
     * (info_hash, peer_id) is what decides which one wins instead of a race
     * inserting the peer twice.
     */
    private function remember(AnnounceRequest $request, int $interval): void
    {
        $now = Carbon::now();

        TrackerPeer::query()->upsert(
            [[
                'info_hash' => $request->infoHash,
                'peer_id' => $request->peerId,
                'ip' => $request->ip,
                'port' => $request->port,
                'left_bytes' => $request->left,
                'uploaded' => $request->uploaded,
                'downloaded' => $request->downloaded,
                'seeder' => $request->left === 0,
                // Two intervals: one missed announce is a lost packet, two is
                // a client that is gone.
                'expires_at' => $now->copy()->addSeconds($interval * 2),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['info_hash', 'peer_id'],
            ['ip', 'port', 'left_bytes', 'uploaded', 'downloaded', 'seeder', 'expires_at', 'updated_at'],
        );
    }

    /**
     * Who to send back.
     *
     * A leecher is given seeders first, because a swarm of leechers pointed at
     * each other is a swarm where nothing finishes; a seeder is given anyone,
     * since it has nothing to fetch and is only there to be found. The order
     * is random inside that, so the same twenty peers are not handed to
     * everyone who joins.
     *
     * @return list<TrackerPeer>
     */
    private function pick(AnnounceRequest $request): array
    {
        if ($request->numwant === 0) {
            return [];
        }

        $base = fn () => TrackerPeer::query()
            ->where('info_hash', $request->infoHash)
            ->where('peer_id', '!=', $request->peerId)
            ->where('expires_at', '>=', now());

        $random = DB::connection()->getDriverName() === 'sqlite' ? 'RANDOM()' : 'RAND()';

        if ($request->left === 0) {
            return $base()->orderByRaw($random)->limit($request->numwant)->get()->all();
        }

        $seeders = $base()
            ->where('seeder', true)
            ->orderByRaw($random)
            ->limit($request->numwant)
            ->get()
            ->all();

        $remaining = $request->numwant - count($seeders);

        if ($remaining <= 0) {
            return $seeders;
        }

        return array_merge($seeders, $base()
            ->where('seeder', false)
            ->orderByRaw($random)
            ->limit($remaining)
            ->get()
            ->all());
    }

    /**
     * The peer list in whichever form the client asked for.
     *
     * Compact (BEP 23) is six bytes per peer — four of address and two of port,
     * big-endian — and IPv6 peers cannot fit in it, so they go into `peers6`
     * (BEP 7) at eighteen bytes each. A client that understands neither still
     * gets `peers` and simply sees no IPv6 members, which is the truth as far
     * as it is concerned.
     *
     * @param  list<TrackerPeer>  $peers
     * @return array<string, mixed>
     */
    private function peerFields(array $peers, bool $compact): array
    {
        if (! $compact) {
            return [
                'peers' => array_values(array_map(fn (TrackerPeer $peer) => [
                    'peer id' => (string) hex2bin($peer->peer_id),
                    'ip' => $peer->ip,
                    'port' => $peer->port,
                ], $peers)),
            ];
        }

        $v4 = '';
        $v6 = '';

        foreach ($peers as $peer) {
            $packed = @inet_pton($peer->ip);

            if ($packed === false) {
                continue;
            }

            $entry = $packed.pack('n', $peer->port);

            if (strlen($packed) === 4) {
                $v4 .= $entry;
            } else {
                $v6 .= $entry;
            }
        }

        $fields = ['peers' => $v4];

        if ($v6 !== '') {
            $fields['peers6'] = $v6;
        }

        return $fields;
    }
}
