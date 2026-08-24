<?php

namespace App\Http\Controllers;

use App\Models\CrmContact;
use App\Services\CrmSyncService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmController extends Controller
{
    /**
     * Pull in whatever the ecosystem knows that the base does not.
     *
     * Synchronous on purpose: this host is not guaranteed a queue worker, and
     * an import that silently never runs is worse than one an operator waits
     * for and can press again. The run records itself, so the lens the
     * operator lands back on prints the new date and what it brought.
     */
    public function sync(CrmSyncService $sync): RedirectResponse
    {
        $counts = $sync->syncAll('operator');

        return back()->with('success', sprintf(
            'Synced: %d platform, %d bridge, %d holders, %d whales (%d new, %d sold)',
            $counts['platform'],
            $counts['bridge'],
            $counts['holders'],
            $counts['whales'],
            $counts['added'],
            $counts['sold'],
        ));
    }

    public function export(): StreamedResponse
    {
        $columns = [
            'id', 'name', 'email', 'telegram', 'x_handle', 'evm_address', 'solana_address',
            'type', 'status', 'source', 'cyber_balance', 'cyber_sol_balance',
            'last_synced_at', 'created_at',
        ];

        $filename = 'crm-contacts-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            CrmContact::query()->orderBy('id')->chunk(500, function ($contacts) use ($out, $columns) {
                foreach ($contacts as $contact) {
                    fputcsv($out, array_map(fn ($col) => (string) $contact->{$col}, $columns));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
