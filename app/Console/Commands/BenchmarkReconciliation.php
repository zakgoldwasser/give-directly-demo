<?php

namespace App\Console\Commands;

use App\Domain\Reconciliation\Services\ReconciliationService;
use App\Models\GuestWorkspace;
use App\Services\ImportService;
use App\Support\Benchmark\ReconciliationBenchmarkDataset;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BenchmarkReconciliation extends Command
{
    protected $signature = 'reconciliation:benchmark
        {--runs=30 : Number of measured warm reconciliation runs}
        {--json : Emit machine-readable JSON}
        {--keep-workspace : Retain benchmark records and objects for inspection}';

    protected $description = 'Measure warm reconciliation against the deterministic 1,000-row benchmark dataset';

    public function handle(
        ReconciliationBenchmarkDataset $dataset,
        ImportService $imports,
        ReconciliationService $reconciliation,
    ): int {
        if (app()->isProduction()) {
            $this->components->error('The benchmark command is disabled in production.');

            return self::FAILURE;
        }

        $runs = filter_var($this->option('runs'), FILTER_VALIDATE_INT);

        if (! is_int($runs) || $runs < 1 || $runs > 100) {
            $this->components->error('--runs must be an integer between 1 and 100.');

            return self::INVALID;
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reconciliation-benchmark-'.Str::uuid();
        $workspace = null;

        try {
            $paths = $dataset->write($directory);
            $workspace = GuestWorkspace::query()->create([
                'session_binding_hash' => hash('sha256', Str::random(64)),
                'expires_at' => now()->addHour(),
                'last_activity_at' => now(),
                'state' => GuestWorkspace::STATE_ACTIVE,
            ]);

            foreach ($paths as $reportType => $path) {
                $imports->createFromUpload(
                    $workspace,
                    new UploadedFile($path, basename($path), 'text/csv', null, true),
                    $reportType,
                );
            }

            $reconciliation->run($workspace, [
                'manual_matches' => ['__benchmark_warmup__' => '__none__'],
            ]);

            $durations = [];

            for ($run = 1; $run <= $runs; $run++) {
                $started = hrtime(true);
                $result = $reconciliation->run($workspace, [
                    'manual_matches' => ["__benchmark_run_{$run}__" => "__none_{$run}__"],
                ]);
                $durations[] = (hrtime(true) - $started) / 1_000_000;

                if ($result === null) {
                    throw new RuntimeException('The benchmark reconciliation did not produce a completed run.');
                }
            }

            sort($durations, SORT_NUMERIC);
            $p95Index = max(0, (int) ceil(count($durations) * 0.95) - 1);
            $result = [
                'dataset_rows' => ReconciliationBenchmarkDataset::TOTAL_ROWS,
                'runs' => $runs,
                'warm_reconciliation_ms' => [
                    'min' => round($durations[0], 2),
                    'median' => round($durations[(int) floor((count($durations) - 1) / 2)], 2),
                    'p95' => round($durations[$p95Index], 2),
                    'max' => round($durations[array_key_last($durations)], 2),
                ],
                'target_p95_ms' => 5_000,
                'target_met' => $durations[$p95Index] < 5_000,
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'environment' => app()->environment(),
                'database' => (string) config('database.default'),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $this->table(['Measurement', 'Value'], [
                    ['Dataset', number_format($result['dataset_rows']).' rows'],
                    ['Runs', (string) $result['runs']],
                    ['Warm min', $result['warm_reconciliation_ms']['min'].' ms'],
                    ['Warm median', $result['warm_reconciliation_ms']['median'].' ms'],
                    ['Warm p95', $result['warm_reconciliation_ms']['p95'].' ms'],
                    ['Warm max', $result['warm_reconciliation_ms']['max'].' ms'],
                    ['Peak memory', $result['peak_memory_mb'].' MiB'],
                    ['Target', $result['target_met'] ? 'PASS (< 5,000 ms p95)' : 'FAIL (>= 5,000 ms p95)'],
                ]);
            }

            return $result['target_met'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error('Benchmark failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($directory);

            if ($workspace !== null && ! (bool) $this->option('keep-workspace')) {
                foreach (['imports', 'exports'] as $disk) {
                    Storage::disk($disk)->deleteDirectory($workspace->id);
                }

                $workspace->delete();
            }
        }
    }
}
