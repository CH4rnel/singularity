<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ProductMetricsService;
use App\Services\Console\NumbersReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
}
