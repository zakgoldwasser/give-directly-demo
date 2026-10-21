<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\CanonicalReportSchema;
use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Services\ImportExceptions\DuplicateImportException;
use App\Services\ImportExceptions\ImportLimitException;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly CanonicalReportSchema $schemas,
    ) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');

        $maxBytes = (int) config('reconciliation.max_csv_size_bytes');

        if ($request->hasFile('file') && $request->file('file')?->getSize() > $maxBytes) {
            return $this->errorResponse(
                $request,
                'FILE_TOO_LARGE',
                'The CSV exceeds the maximum allowed size.',
                413,
            );
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) ceil($maxBytes / 1024)],
            'report_type' => ['required', 'in:donations,payouts,crm'],
        ]);

        try {
            $batch = $this->imports->createFromUpload(
                $workspace,
                $validated['file'],
                $validated['report_type'],
            );
        } catch (DuplicateImportException $exception) {
            return $this->duplicateResponse($request, $exception);
        } catch (ImportLimitException $exception) {
            $status = $exception->errorCode === 'FILE_TOO_LARGE' ? 413 : 422;

            return $this->limitResponse($request, $exception, $status);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'redirect' => route('try.imports.show', $batch),
                ],
            ], 201);
        }

        return redirect()->route('try.imports.show', $batch);
    }

    public function show(Request $request, string $import): View|JsonResponse
    {
        $batch = $this->resolveBatch($request, $import);

        $previewRows = [];
        $headers = $batch->parsing_config['headers'] ?? [];
        $schemaFields = $this->schemas->fields($batch->report_type);
        $currentMapping = $batch->mappingProfile?->mapping ?? [];

        if ($currentMapping === []) {
            foreach (array_keys($schemaFields) as $canonicalField) {
                if (in_array($canonicalField, $headers, true)) {
                    $currentMapping[$canonicalField] = $canonicalField;
                }
            }
        }

        if (in_array($batch->status, [
            ImportBatch::STATUS_MAPPING_REQUIRED,
            ImportBatch::STATUS_VALIDATED,
            ImportBatch::STATUS_VALIDATION_FAILED,
            ImportBatch::STATUS_SUPERSEDED,
        ], true)) {
            $previewRows = $batch->rows()
                ->orderBy('row_number')
                ->limit(20)
                ->get();
        }

        $data = [
            'batch' => $batch,
            'previewRows' => $previewRows,
            'headers' => $headers,
            'schemaFields' => $schemaFields,
            'currentMapping' => $currentMapping,
            'duplicateWarning' => $request->session()->pull('import_duplicate_warning'),
            'mappingSaved' => $request->session()->pull('mapping_saved'),
            'minutesRemaining' => $request->attributes->get('guest_workspace')->minutesRemaining(),
        ];

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $batch->id,
                'report_type' => $batch->report_type,
                'status' => $batch->status,
                'html' => view('public.import-content', [...$data, 'modal' => true])->render(),
                'card_html' => view('public.report-state', ['batch' => $batch])->render(),
            ]]);
        }

        return view('public.import', $data);
    }

    private function resolveBatch(Request $request, string $import): ImportBatch
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');

        $batch = $this->imports->findForWorkspace($workspace, $import);

        abort_if($batch === null, 404);

        return $batch->load('mappingProfile');
    }

    private function duplicateResponse(Request $request, DuplicateImportException $exception): RedirectResponse|JsonResponse
    {
        $batch = $exception->existingBatch;

        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'DUPLICATE_FILE',
                    'message' => $exception->getMessage(),
                ],
                'data' => [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'redirect' => route('try.imports.show', $batch),
                ],
            ], 409);
        }

        return redirect()
            ->route('try.imports.show', $batch)
            ->with('import_duplicate_warning', $exception->getMessage());
    }

    private function limitResponse(
        Request $request,
        ImportLimitException $exception,
        int $status = 422,
    ): RedirectResponse|JsonResponse {
        return $this->errorResponse($request, $exception->errorCode, $exception->getMessage(), $status);
    }

    private function errorResponse(
        Request $request,
        string $code,
        string $message,
        int $status,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], $status);
        }

        return redirect()
            ->route('try.show')
            ->withErrors(['file' => $message]);
    }
}
