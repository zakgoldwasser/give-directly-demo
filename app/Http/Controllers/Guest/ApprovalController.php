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
        ]);
        $count = $this->approvals->approveEligible($workspace, $validated['run_id']);

        return redirect()->route('try.show')->with(
            'approval_saved',
            number_format($count).' eligible changes approved for the current run.',
        );
    }
}
