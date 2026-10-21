<?php

namespace App\Services;

use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ReferenceScenarioService
{
    public function __construct(
        private readonly ImportService $imports,
    ) {}

    /**
     * @return Collection<int, ImportBatch>
     */
    public function load(GuestWorkspace $workspace, string $scenarioId = 'reference_v1'): Collection
    {
        $created = collect();

        DB::beginTransaction();

        try {
            foreach (['donations', 'payouts', 'crm'] as $reportType) {
                $batch = $this->imports->createFromFixture($workspace, $reportType, $scenarioId);
                $created->push($batch);
            }

            DB::commit();

            return $created;
        } catch (Throwable $exception) {
            DB::rollBack();

            foreach ($created as $batch) {
                Storage::disk('imports')->delete($batch->private_file_path);
            }

            throw new RuntimeException('The guided reference scenario could not be loaded.', previous: $exception);
        }
    }
}
