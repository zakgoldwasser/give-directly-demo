<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ExportService;
use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exports,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $validated = $request->validate([
            'run_id' => ['required', 'uuid'],
        ]);
        $batch = $this->exports->prepare($workspace, $validated['run_id']);

        return redirect()->route('try.show')->with(
            'export_prepared',
            "Export {$batch->id} is ready to download.",
        );
    }
}
