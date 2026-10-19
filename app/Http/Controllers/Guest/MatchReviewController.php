<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ReviewDecisionService;
use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MatchReviewController extends Controller
{
    public function __construct(
        private readonly ReviewDecisionService $reviews,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $validated = $request->validate([
            'transaction_id' => ['required', 'string', 'max:255'],
            'crm_record_id' => ['required', 'string', 'max:255'],
        ]);

        $this->reviews->confirmCrmMatch($workspace, $validated['transaction_id'], $validated['crm_record_id']);

        return redirect()->route('try.show')->with('review_saved', 'CRM candidate confirmed and reconciliation rerun.');
    }
}
