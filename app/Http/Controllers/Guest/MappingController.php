<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Exceptions\InvalidMappingException;
use App\Domain\Reconciliation\Services\MappingValidationService;
use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MappingController extends Controller
{
    public function __construct(
        private readonly MappingValidationService $mappings,
    ) {}

    public function __invoke(Request $request, string $import): RedirectResponse|JsonResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');

        $batch = ImportBatch::query()
            ->where('guest_workspace_id', $workspace->id)
            ->whereKey($import)
            ->firstOrFail();

        abort_if($batch->status === ImportBatch::STATUS_SUPERSEDED, 409);

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:4096'],
        ]);

        try {
            $batch = $this->mappings->saveMapping($batch, $validated['mapping']);
        } catch (InvalidMappingException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'INVALID_MAPPING',
                        'message' => $exception->getMessage(),
                        'issues' => $exception->issues,
                    ],
                ], 422);
            }

            return back()->withErrors([
                'mapping' => $exception->issues[0]['message'] ?? $exception->getMessage(),
            ])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'mapping_profile_version' => $batch->mappingProfile?->version,
                ],
            ]);
        }

        return redirect()
            ->route('try.imports.show', $batch)
            ->with('mapping_saved', 'Mapping saved as a new version and validation rerun.');
    }
}
