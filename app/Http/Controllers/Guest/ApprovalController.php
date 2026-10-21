<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ApprovalService;
use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $validated = $request->validate([
            'run_id' => ['required', 'uuid'],
            'decision' => ['sometimes', 'required', 'in:approve,exclude,restore'],
            'transaction_id' => ['required_if:decision,exclude,restore', 'nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        if (in_array($validated['decision'] ?? 'approve', ['exclude', 'restore'], true)) {
            $restore = $validated['decision'] === 'restore';
            $this->approvals->exclude($workspace, $validated['run_id'], $validated['transaction_id'], $validated['reason'] ?? '', $restore);

            return redirect()->route('try.show')->with('approval_saved', $restore
                ? 'Change restored to this export review.'
                : 'Change excluded from this export. Other changes can still be approved.');
        }
        $count = $this->approvals->approveEligible($workspace, $validated['run_id'], $validated['transaction_id'] ?? null);

        return redirect()->route('try.show')->with(
            'approval_saved',
            number_format($count).' eligible changes approved for the current run.',
        );
    }
}
