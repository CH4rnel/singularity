<?php

namespace App\Services\Tracker;

use RuntimeException;

/**
 * A refusal a BitTorrent client can read.
 *
 * The protocol has exactly one way to say no: a bencoded dictionary with a
 * `failure reason` in it, sent with a 200. A 4xx and a JSON body is what a
 * client reports as "tracker is down", which is the wrong sentence for "this
 * torrent is not on this tracker" — so every refusal in this namespace is one
 * of these, and the controller turns it into that dictionary.
 */
class TrackerFailure extends RuntimeException {}
