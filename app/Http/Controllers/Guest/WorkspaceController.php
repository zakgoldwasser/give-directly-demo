<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'intent' => ['required', 'in:guided,scenarios'],
        ]);

        $this->workspaces->start($validated['intent']);

        return redirect()->route('try.show');
    }

    public function show(Request $request): View
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');

        return view('public.try', [
            'workspace' => $workspace,
            'intent' => $this->workspaces->intent(),
            'maxCsvSizeMb' => round(((int) config('reconciliation.max_csv_size_bytes')) / 1024 / 1024, 1),
            'maxDataRows' => (int) config('reconciliation.max_data_rows'),
            'maxColumns' => (int) config('reconciliation.max_columns'),
            'maxFieldLength' => (int) config('reconciliation.max_field_length'),
            'maxActiveImports' => (int) config('reconciliation.max_active_imports_per_session'),
            'workspaceLifetimeMinutes' => (int) config('reconciliation.workspace_lifetime_minutes'),
            'arbitraryUploadsAllowed' => (bool) config('reconciliation.allow_arbitrary_uploads'),
            'minutesRemaining' => $workspace->minutesRemaining(),
        ]);
    }

    public function reset(): RedirectResponse
    {
        $this->workspaces->reset();

        return redirect()->route('try.show');
    }
}
