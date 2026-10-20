<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ReadinessController extends Controller
{
    /** @var array<string, array<string, string>> */
    private array $failures = [];

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseReady(),
            'storage_configuration' => $this->storageConfigurationReady(),
            'imports_storage' => $this->storageReady('imports'),
            'exports_storage' => $this->storageReady('exports'),
        ];
        $ready = ! in_array(false, $checks, true);

        $payload = [
            'status' => $ready ? 'ready' : 'unavailable',
            'checks' => $checks,
        ];

        if (! $ready && $this->failures !== []) {
            $payload['failures'] = $this->failures;
        }

        return response()->json($payload, $ready ? 200 : 503);
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
            $storage = $this->readinessStorage($disk);
            $written = $storage->put($path, $contents);
            $ready = $written
                && $storage->exists($path)
                && hash_equals($contents, $storage->get($path));
        } catch (Throwable $exception) {
            $this->logFailure($disk.'_storage', $exception);
        } finally {
            if ($written) {
                try {
                    if (! $storage->delete($path)) {
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

    private function readinessStorage(string $disk): Filesystem
    {
        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return Storage::disk($disk);
        }

        return Storage::build([
            ...config("filesystems.disks.{$disk}"),
            'throw' => true,
        ]);
    }

    private function logFailure(string $check, Throwable $exception): void
    {
        $failure = ['exception' => class_basename($exception)];
        $causes = [];
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current !== $exception) {
                $causes[] = class_basename($current);
            }

            if (! isset($failure['aws_error_code']) && method_exists($current, 'getAwsErrorCode')) {
                $awsErrorCode = $current->getAwsErrorCode();

                if (is_string($awsErrorCode) && $awsErrorCode !== '') {
                    $failure['aws_error_code'] = $awsErrorCode;
                }
            }

            $current = $current->getPrevious();
        }

        if ($causes !== []) {
            $failure['causes'] = implode(', ', $causes);
        }

        $this->failures[$check] = $failure;

        Log::warning('Readiness check failed.', [
            'check' => $check,
            'exception_class' => $exception::class,
        ]);
    }
}
