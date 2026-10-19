<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\PostImportVerificationService;
use App\Http\Controllers\Controller;
use App\Models\ConfirmationBatch;
use App\Models\GuestWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PostImportVerificationController extends Controller
{
    public function __invoke(
        Request $request,
        ConfirmationBatch $confirmation,
        PostImportVerificationService $verification,
    ): RedirectResponse {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $run = $verification->verify($workspace, $confirmation);
        $unchanged = $run->outcome_counts['unchanged'] ?? 0;
        $held = $run->outcome_counts['held'] ?? 0;

        return redirect()->route('try.show')->with(
            'verification_completed',
            "Post-import verification run {$run->sequence} completed: {$unchanged} unchanged and {$held} held.",
        );
    }
}
