<?php

namespace Tests\Feature;

use App\Support\Deployment\DeploymentVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeploymentVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_closed_for_unsafe_release_configuration_without_disclosing_secrets(): void
    {
        Storage::fake('imports');
        Storage::fake('exports');
        config([
            'app.debug' => true,
            'app.key' => 'short',
            'app.url' => 'http://example.test',
            'session.driver' => 'array',
            'session.secure' => false,
            'cache.default' => 'array',
            'queue.default' => 'database',
            'reconciliation.readiness_secret' => 'readiness-value-that-must-not-appear',
            'reconciliation.cron_secret' => 'cron-value-that-must-not-appear',
        ]);

        $checks = app(DeploymentVerifier::class)->verify();
        $byName = collect($checks)->keyBy('name');
        $encoded = json_encode($checks, JSON_THROW_ON_ERROR);

        $this->assertFalse($byName['Debug mode']['passed']);
        $this->assertFalse($byName['Application key']['passed']);
        $this->assertFalse($byName['Application URL']['passed']);
        $this->assertFalse($byName['Session driver']['passed']);
        $this->assertFalse($byName['Secure session cookie']['passed']);
        $this->assertFalse($byName['Cache driver']['passed']);
        $this->assertFalse($byName['Queue driver']['passed']);
        $this->assertStringNotContainsString('readiness-value-that-must-not-appear', $encoded);
        $this->assertStringNotContainsString('cron-value-that-must-not-appear', $encoded);
    }
}
