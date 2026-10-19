<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ConfirmationService;
use App\Http\Controllers\Controller;
use App\Models\ConfirmationResult;
use App\Models\ExportBatch;
use App\Models\GuestWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConfirmationController extends Controller
{
    public function __invoke(Request $request, ExportBatch $export, ConfirmationService $confirmations): RedirectResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $batch = $confirmations->confirm($workspace, $export);
        $matched = $batch->counts[ConfirmationResult::OUTCOME_MATCHES] ?? 0;
        $total = array_sum($batch->counts);

        return redirect()->route('try.show')->with(
            'confirmation_completed',
            "Simulated CRM confirmation completed: {$matched} of {$total} exported actions match.",
        );
    }
}
