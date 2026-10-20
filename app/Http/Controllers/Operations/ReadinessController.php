<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseReady(),
            'storage_configuration' => $this->storageConfigurationReady(),
            'imports_storage' => $this->storageReady('imports'),
            'exports_storage' => $this->storageReady('exports'),
        ];
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    private function storageConfigurationReady(): bool
    {
        if (! app()->isProduction()) {
            return true;
        }

        return collect(['imports', 'exports'])
            ->every(fn (string $disk): bool => config("filesystems.disks.{$disk}.driver") === 's3');
    }

    private function databaseReady(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable $exception) {
            $this->logFailure('database', $exception);

            return false;
        }
    }

    private function storageReady(string $disk): bool
    {
        $path = '.readiness/'.Str::uuid().'.txt';
        $contents = Str::random(32);
        $written = false;
        $ready = false;

        try {
            $written = Storage::disk($disk)->put($path, $contents);
            $ready = $written
                && Storage::disk($disk)->exists($path)
                && hash_equals($contents, Storage::disk($disk)->get($path));
        } catch (Throwable $exception) {
            $this->logFailure($disk.'_storage', $exception);
        } finally {
            if ($written) {
                try {
                    if (! Storage::disk($disk)->delete($path)) {
                        $ready = false;
                    }
                } catch (Throwable $exception) {
                    $this->logFailure($disk.'_storage_cleanup', $exception);
                    $ready = false;
                }
            }
        }

        return $ready;
    }

    private function logFailure(string $check, Throwable $exception): void
    {
        Log::warning('Readiness check failed.', [
            'check' => $check,
            'exception_class' => $exception::class,
        ]);
    }
}
