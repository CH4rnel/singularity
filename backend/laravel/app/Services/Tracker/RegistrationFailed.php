<?php

namespace App\Services\Tracker;

use RuntimeException;

/**
 * Why a token could not become a release.
 *
 * These reach a person rather than a torrent client, and by the time one is
 * thrown the mint has already happened and been paid for — so the message says
 * which of the three things went wrong (the token, the document it points at,
 * or the torrent described in it) precisely enough to fix and register again.
 */
class RegistrationFailed extends RuntimeException {}
