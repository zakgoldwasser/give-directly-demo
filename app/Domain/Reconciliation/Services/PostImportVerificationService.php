<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\ConfirmationBatch;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ReconciliationRun;
use App\Services\ImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PostImportVerificationService
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly ReconciliationService $reconciliation,
        private readonly ReviewDecisionService $reviews,
        private readonly ReferenceFixtureCatalog $fixtures,
    ) {}

    public function verify(GuestWorkspace $workspace, ConfirmationBatch $confirmation): ReconciliationRun
    {
        if ($confirmation->guest_workspace_id !== $workspace->id) {
            abort(404);
        }

        $createdPath = null;

        try {
            return DB::transaction(function () use ($workspace, $confirmation, &$createdPath): ReconciliationRun {
                GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                $locked = ConfirmationBatch::query()
                    ->whereKey($confirmation->id)
                    ->where('guest_workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->verification_reconciliation_run_id !== null) {
                    return $locked->verificationRun()->firstOrFail();
                }

                $snapshot = $this->fixtures->confirmationSnapshot();

                if (! hash_equals((string) $snapshot['sha256'], $locked->snapshot_checksum)) {
                    throw ValidationException::withMessages([
                        'verification' => 'The confirmed snapshot no longer matches the reference fixture.',
                    ]);
                }

                foreach ($snapshot['expected'] as $outcome => $count) {
                    if (($locked->counts[$outcome] ?? null) !== $count) {
                        throw ValidationException::withMessages([
                            'verification' => 'Resolve every confirmation exception before promoting this snapshot.',
                        ]);
                    }
                }

                foreach ($snapshot['expected'] as $outcome => $count) {
                    if (($locked->counts[$outcome] ?? null) !== $count) {
                        throw ValidationException::withMessages([
                            'verification' => 'Resolve every confirmation exception before promoting this snapshot.',
                        ]);
                    }
                }

                $workspace->importBatches()
                    ->where('report_type', 'crm')
                    ->where('status', ImportBatch::STATUS_VALIDATED)
                    ->update(['status' => ImportBatch::STATUS_SUPERSEDED]);

                $verificationImport = $this->imports->createFromConfirmationSnapshot($workspace, $locked);
                $createdPath = $verificationImport->private_file_path;
                $run = $this->reconciliation->run($workspace, $this->reviews->currentDecisions($workspace));

                if ($run === null) {
                    throw new RuntimeException('The post-import reconciliation could not be created.');
                }

                $expected = $snapshot['verification_outcomes'];

                foreach ($expected as $outcome => $count) {
                    if (($run->outcome_counts[$outcome] ?? null) !== $count) {
                        throw new RuntimeException('The post-import reconciliation did not match the reference outcome contract.');
                    }
                }

                $locked->forceFill([
                    'verification_import_batch_id' => $verificationImport->id,
                    'verification_reconciliation_run_id' => $run->id,
                    'verification_counts' => $run->outcome_counts,
                    'verification_completed_at' => now(),
                    'status' => ConfirmationBatch::STATUS_VERIFIED,
                ])->save();

                return $run;
            });
        } catch (Throwable $exception) {
            if ($createdPath !== null) {
                Storage::disk('imports')->delete($createdPath);
            }

            throw $exception;
        }
    }
}
