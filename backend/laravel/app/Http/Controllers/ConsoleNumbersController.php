<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ProductMetricsService;
use App\Services\Console\NumbersReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Числа" — six questions instead of two analytics pages.
 *
 * `?subject=installs` counts installations of the wallet, `?subject=sessions`
 * counts browsers reading the site. They were separate pages before, which is
 * how "user" came to mean two different things in the same conversation; one
 * switch makes the subject a choice somebody makes on purpose.
 */
class ConsoleNumbersController extends Controller
{
    public function __construct(
        private NumbersReport $report,
        private ProductMetricsService $metrics,
    ) {}

    public function index(Request $request): Response
    {
        $subject = in_array($request->query('subject'), NumbersReport::SUBJECTS, true)
            ? (string) $request->query('subject')
            : 'installs';

        $filters = AnalyticsFilters::fromRequest($request);

        return Inertia::render('crm/Numbers', [
            'subject' => $subject,
            'subjects' => NumbersReport::SUBJECTS,
            'filters' => $filters->toArray(),
            'options' => $subject === 'installs' ? $this->metrics->filterOptions() : null,
            'questions' => $this->report->questions($subject, $filters),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $subject = in_array($request->query('subject'), NumbersReport::SUBJECTS, true)
            ? (string) $request->query('subject')
            : 'installs';
        $filters = AnalyticsFilters::fromRequest($request);
        $questions = $this->report->questions($subject, $filters);
        $filename = sprintf('crm-product-%s-%s.csv', $subject, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($filters, $questions, $subject): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'subject', 'from', 'to', 'question', 'value', 'unit',
                'evidence_type', 'evidence_json', 'caveat_key',
            ]);

            foreach ($questions as $question) {
                fputcsv($out, [
                    $subject,
                    $filters->from->toDateString(),
                    $filters->to->toDateString(),
                    $question['key'],
                    $question['answer']['value'],
                    $question['answer']['unit'],
                    $question['evidence']['type'] ?? null,
                    json_encode($question['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $question['caveat']['key'] ?? null,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
