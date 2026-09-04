<?php

namespace App\Http\Controllers;

use App\Services\Tracker\AnnounceRequest;
use App\Services\Tracker\TrackerAnnounceService;
use App\Services\Tracker\TrackerFailure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The two endpoints a BitTorrent client talks to.
 *
 * Everything here answers in bencode with a 200, including every refusal: the
 * protocol's only way to say no is a `failure reason` inside the dictionary,
 * and a client that receives a 4xx reports the tracker as down instead of
 * showing the sentence. So the status code carries no information and the body
 * carries all of it.
 *
 * The query string is read raw. An announce carries two twenty-byte binary
 * values, and the framework's string handling would trim a hash whose first
 * byte happens to be whitespace — see AnnounceRequest for why that is not
 * paranoia.
 */
class TrackerAnnounceController extends Controller
{
    public function __construct(private readonly TrackerAnnounceService $tracker) {}

    public function announce(Request $request): Response
    {
        try {
            $body = $this->tracker->announce(AnnounceRequest::fromPairs(
                AnnounceRequest::pairs($this->queryString($request)),
                (string) $request->ip(),
            ));
        } catch (TrackerFailure $failure) {
            $body = $this->tracker->failure($failure->getMessage());
        }

        return $this->bencoded($body);
    }

    public function scrape(Request $request): Response
    {
        $hashes = [];

        foreach (AnnounceRequest::pairs($this->queryString($request)) as [$key, $value]) {
            if ($key === 'info_hash' && strlen($value) === 20) {
                $hashes[] = bin2hex($value);
            }
        }

        return $this->bencoded($this->tracker->scrape($hashes));
    }

    /**
     * The bytes the client actually sent.
     *
     * `QUERY_STRING` when there is one; Symfony's normalised form otherwise,
     * which reorders pairs but preserves every byte inside them — and this
     * reads pairs, not positions.
     */
    private function queryString(Request $request): string
    {
        $raw = (string) $request->server('QUERY_STRING', '');

        return $raw !== '' ? $raw : (string) $request->getQueryString();
    }

    private function bencoded(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=ISO-8859-1',
            'Cache-Control' => 'no-store',
        ]);
    }
}
