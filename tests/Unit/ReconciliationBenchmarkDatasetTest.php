<?php

namespace Tests\Unit;

use App\Domain\Reconciliation\Services\CsvParserService;
use App\Support\Benchmark\ReconciliationBenchmarkDataset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciliationBenchmarkDatasetTest extends TestCase
{
    public function test_it_builds_the_versioned_one_thousand_row_benchmark_dataset(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'benchmark-dataset-test-'.Str::uuid();

        try {
            $paths = app(ReconciliationBenchmarkDataset::class)->write($directory);
            $parser = app(CsvParserService::class);
            $counts = array_map(fn (string $path): int => $parser->parse($path)['total_rows'], $paths);

            $this->assertSame([
                'donations' => ReconciliationBenchmarkDataset::DONATION_ROWS,
                'payouts' => ReconciliationBenchmarkDataset::PAYOUT_ROWS,
                'crm' => ReconciliationBenchmarkDataset::CRM_ROWS,
            ], $counts);
            $this->assertSame(ReconciliationBenchmarkDataset::TOTAL_ROWS, array_sum($counts));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
