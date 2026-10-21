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
        if (! $request->has('action') && $request->has('crm_record_id') && ! $request->filled('crm_record_id')) {
            $request->merge(['action' => 'reject']);
        }
        $validated = $request->validate([
            'transaction_id' => ['required', 'string', 'max:255'],
            'crm_record_id' => ['required_if:action,confirm', 'required_without:action', 'nullable', 'string', 'max:255'],
            'action' => ['sometimes', 'required', 'in:confirm,reject,undo'],
            'run_id' => ['sometimes', 'required', 'uuid'],
        ]);

        $this->reviews->confirmCrmMatch($workspace, $validated['transaction_id'], $validated['crm_record_id'] ?? '', $validated['action'] ?? 'confirm', $validated['run_id'] ?? null);

        return redirect()->route('try.show')->with('review_saved', match ($validated['action'] ?? 'confirm') {
            'reject' => 'CRM candidates rejected. The donation remains held for review.',
            'undo' => 'Match decision undone and reconciliation rerun.',
            default => 'CRM candidate confirmed and reconciliation rerun.',
        });
    }
}
