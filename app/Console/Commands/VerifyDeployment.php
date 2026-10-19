<?php

namespace App\Console\Commands;

use App\Support\Deployment\DeploymentVerifier;
use Illuminate\Console\Command;

class VerifyDeployment extends Command
{
    protected $signature = 'reconciliation:verify-deployment
        {--json : Emit machine-readable JSON}
        {--allow-local : Permit HTTP, local object storage, and an insecure cookie for a local container rehearsal}';

    protected $description = 'Fail unless production configuration, migrations, database, and private storage are ready';

    public function handle(DeploymentVerifier $verifier): int
    {
        $checks = $verifier->verify((bool) $this->option('allow-local'));
        $passed = collect($checks)->every(fn (array $check): bool => $check['passed']);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'checks' => $checks,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Check', 'Result', 'Detail'],
                array_map(fn (array $check): array => [
                    $check['name'],
                    $check['passed'] ? 'PASS' : 'FAIL',
                    $check['detail'],
                ], $checks),
            );
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
