<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ReviewDecisionService;
use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CampaignReviewController extends Controller
{
    public function __construct(
        private readonly ReviewDecisionService $reviews,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $validated = $request->validate([
            'campaign_code' => ['required', 'string', 'max:255'],
            'campaign_id' => ['required', 'string', 'max:255'],
        ]);

        $this->reviews->saveCampaignMapping($workspace, $validated['campaign_code'], $validated['campaign_id']);

        return redirect()->route('try.show')->with('review_saved', 'Campaign mapping saved and reconciliation rerun.');
    }
}
