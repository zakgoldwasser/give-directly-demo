<?php

namespace App\Support\Deployment;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class DeploymentVerifier
{
    /**
     * @return list<array{name: string, passed: bool, detail: string}>
     */
    public function verify(bool $allowLocal = false): array
    {
        $checks = [];
        $record = function (string $name, bool $passed, string $detail) use (&$checks): void {
            $checks[] = compact('name', 'passed', 'detail');
        };

        $record('Application environment', app()->isProduction(), app()->environment());
        $record('Debug mode', config('app.debug') === false, config('app.debug') ? 'enabled' : 'disabled');
        $record('Application key', $this->hasSecret(config('app.key'), 32), 'configured value is at least 32 characters');
        $record('Application URL', $allowLocal || str_starts_with((string) config('app.url'), 'https://'), (string) config('app.url'));
        $record('Database driver', config('database.default') === 'pgsql', (string) config('database.default'));
        $record(
            'Database encryption',
            $allowLocal || in_array(config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true),
            (string) config('database.connections.pgsql.sslmode'),
        );
        $record('Session driver', config('session.driver') === 'database', (string) config('session.driver'));
        $record('Secure session cookie', $allowLocal || config('session.secure') === true, config('session.secure') ? 'enabled' : 'disabled');
        $record('Cache driver', config('cache.default') === 'database', (string) config('cache.default'));
        $record('Queue driver', config('queue.default') === 'sync', (string) config('queue.default'));

        foreach (['imports', 'exports'] as $disk) {
            $driver = (string) config("filesystems.disks.{$disk}.driver");
            $record(
                ucfirst($disk).' storage driver',
                $driver === 's3' || ($allowLocal && $driver === 'local'),
                $driver,
            );
        }

        $importsRoot = (string) config('filesystems.disks.imports.root');
        $exportsRoot = (string) config('filesystems.disks.exports.root');
        $record('Distinct object prefixes', $importsRoot !== '' && $exportsRoot !== '' && $importsRoot !== $exportsRoot, 'configured');
        $record('Readiness secret', $this->hasSecret(config('reconciliation.readiness_secret'), 16), 'configured value is at least 16 characters');
        $record('Cron secret', $this->hasSecret(config('reconciliation.cron_secret'), 16), 'configured value is at least 16 characters');

        try {
            DB::select('select 1');
            $record('Database connection', true, 'reachable');
        } catch (Throwable $exception) {
            $record('Database connection', false, $exception::class);
        }

        try {
            $migrator = app(Migrator::class);
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
            $record('Database migrations', $pending === [], $pending === [] ? 'current' : count($pending).' pending');
        } catch (Throwable $exception) {
            $record('Database migrations', false, $exception::class);
        }

        foreach (['imports', 'exports'] as $disk) {
            $this->verifyStorage($disk, $record);
        }

        return $checks;
    }

    private function hasSecret(mixed $value, int $minimumLength): bool
    {
        return is_string($value) && strlen($value) >= $minimumLength;
    }

    /** @param callable(string, bool, string): void $record */
    private function verifyStorage(string $disk, callable $record): void
    {
        $path = 'deployment-probes/'.Str::uuid().'.txt';
        $payload = Str::random(48);

        try {
            $storage = Storage::disk($disk);
            $written = $storage->put($path, $payload);
            $read = $written ? $storage->get($path) : null;
            $deleted = $storage->delete($path);
            $passed = $written && hash_equals($payload, (string) $read) && $deleted && ! $storage->exists($path);
            $record(ucfirst($disk).' storage access', $passed, $passed ? 'write/read/delete passed' : 'probe failed');
        } catch (Throwable $exception) {
            $record(ucfirst($disk).' storage access', false, $exception::class);
        }
    }
}
