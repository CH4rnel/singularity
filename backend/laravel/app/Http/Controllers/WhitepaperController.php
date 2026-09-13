<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * /whitepaper — the roadmap as a page instead of a download.
 *
 * The document was a .docx behind a nav link, which is three things a reader
 * will not do: download a file to read a thesis, open it on a phone, and quote
 * a line of it to somebody else. So it is rendered like /cyber is rendered —
 * one claim per block, with the live surface that proves it underneath.
 *
 * The controller computes nothing the content file could not state, with one
 * exception: the tally of phases by status is *derived* from the phase list
 * rather than written beside it. A headline saying "six shipped" over a list
 * of five is the one arithmetic error a roadmap makes on its own, and it
 * always survives longer than the edit that caused it.
 */
class WhitepaperController extends Controller
{
    /**
     * The order statuses are counted and rendered in — worst-to-best would be
     * a strange way to read a roadmap, so it runs shipped-first.
     */
    private const STATUSES = ['live', 'progress', 'planned', 'vision'];

    public function __invoke(): Response
    {
        /** @var list<array<string, mixed>> $phases */
        $phases = (array) config('whitepaper.phases', []);

        return Inertia::render('Whitepaper', [
            'thesis' => (string) config('whitepaper.thesis'),
            'pillars' => (array) config('whitepaper.pillars', []),
            'phases' => $phases,
            'references' => (array) config('whitepaper.references', []),
            'document' => [
                'href' => (string) config('whitepaper.document'),
                'date' => (string) config('whitepaper.document_date'),
            ],
            'reviewedAt' => (string) config('whitepaper.reviewed_at'),
            'tally' => $this->tally($phases),
        ]);
    }

    /**
     * How many phases sit in each state.
     *
     * @param  list<array<string, mixed>>  $phases
     * @return array<string, int>
     */
    private function tally(array $phases): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($phases as $phase) {
            $status = (string) ($phase['status'] ?? '');

            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }
}
