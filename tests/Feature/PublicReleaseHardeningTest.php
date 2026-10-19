<?php

namespace Tests\Feature;

use App\Models\GuestWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        Storage::fake('exports');
    }

    public function test_workspace_starts_are_limited_per_session(): void
    {
        config([
            'reconciliation.workspace_start_limits.per_session' => 2,
            'reconciliation.workspace_start_limits.per_ip' => 100,
        ]);
        $sessionCookie = (string) config('session.cookie');
        $sessionId = Str::random(40);

        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('try.start'), ['intent' => 'scenarios'])
            ->assertRedirect(route('try.show'));
        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('try.start'), ['intent' => 'scenarios'])
            ->assertRedirect(route('try.show'));
        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('try.start'), ['intent' => 'scenarios'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->assertDatabaseCount('guest_workspaces', 2);
    }

    public function test_workspace_starts_are_limited_per_ip_across_sessions(): void
    {
        config([
            'app.running_on_vercel' => true,
            'reconciliation.workspace_start_limits.per_session' => 100,
            'reconciliation.workspace_start_limits.per_ip' => 2,
        ]);
        $sessionCookie = (string) config('session.cookie');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withCookie($sessionCookie, Str::random(40))
                ->withHeader('x-vercel-forwarded-for', '203.0.113.20')
                ->post(route('try.start'), ['intent' => 'scenarios'])
                ->assertRedirect(route('try.show'));
        }

        $this->withCookie($sessionCookie, Str::random(40))
            ->withHeader('x-vercel-forwarded-for', '203.0.113.20')
            ->post(route('try.start'), ['intent' => 'scenarios'])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('guest_workspaces', 2);
    }

    public function test_workspace_work_is_limited_before_an_operation_starts(): void
    {
        config([
            'reconciliation.workspace_work_limits.per_session' => 2,
            'reconciliation.workspace_work_limits.per_ip' => 100,
        ]);
        $sessionCookie = (string) config('session.cookie');
        $sessionId = Str::random(40);
        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('try.start'), ['intent' => 'scenarios']);

        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('try.imports.store'))
            ->assertSessionHasErrors();
        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('try.imports.store'))
            ->assertSessionHasErrors();
        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('try.imports.store'))
            ->assertTooManyRequests();

        $this->assertDatabaseCount('workspace_operations', 2);
    }

    public function test_new_workspaces_can_be_disabled_server_side(): void
    {
        config(['reconciliation.new_workspaces_enabled' => false]);

        $this->postJson(route('try.start'), ['intent' => 'scenarios'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'NEW_WORKSPACES_DISABLED')
            ->assertHeader('X-Trace-Id');

        $this->assertDatabaseCount('guest_workspaces', 0);
    }

    public function test_readiness_requires_a_configured_secret_and_checks_database_and_storage(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('operations.ready');

        $this->assertNotNull($route);
        $this->assertNotContains(
            StartSession::class,
            $this->app['router']->gatherRouteMiddleware($route),
        );

        $this->getJson(route('operations.ready'))
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'ENDPOINT_NOT_CONFIGURED');

        config(['reconciliation.readiness_secret' => 'readiness-secret-123456']);

        $this->getJson(route('operations.ready'))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer');

        $this->withToken('readiness-secret-123456')
            ->getJson(route('operations.ready'))
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => [
                    'database' => true,
                    'imports_storage' => true,
                    'exports_storage' => true,
                ],
            ]);

        $this->assertSame([], Storage::disk('imports')->allFiles());
        $this->assertSame([], Storage::disk('exports')->allFiles());
    }

    public function test_authenticated_cleanup_sweeps_are_bounded_and_idempotent(): void
    {
        config([
            'reconciliation.cron_secret' => 'cron-secret-1234567890',
            'reconciliation.cleanup_batch_size' => 2,
        ]);
        GuestWorkspace::factory()->count(3)->create([
            'expires_at' => now()->subMinute(),
            'state' => GuestWorkspace::STATE_EXPIRED,
        ]);

        $this->getJson(route('operations.workspaces.purge'))->assertUnauthorized();

        $this->withToken('cron-secret-1234567890')
            ->getJson(route('operations.workspaces.purge'))
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'purged' => 2,
                'failed' => 0,
            ]);
        $this->assertDatabaseCount('guest_workspaces', 1);

        $this->withToken('cron-secret-1234567890')
            ->getJson(route('operations.workspaces.purge'))
            ->assertOk()
            ->assertJson(['purged' => 1, 'failed' => 0]);

        $this->withToken('cron-secret-1234567890')
            ->getJson(route('operations.workspaces.purge'))
            ->assertOk()
            ->assertJson(['purged' => 0, 'failed' => 0]);
    }

    public function test_vercel_configuration_caps_requests_and_schedules_cleanup(): void
    {
        $configuration = json_decode(
            file_get_contents(base_path('vercel.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(30, $configuration['services']['app']['maxDuration']);
        $this->assertSame('container', $configuration['services']['app']['runtime']);
        $this->assertSame('Dockerfile.vercel', $configuration['services']['app']['entrypoint']);
        $this->assertContains([
            'path' => '/internal/cron/workspaces/purge',
            'schedule' => '*/15 * * * *',
        ], $configuration['crons']);
        $this->assertFileExists(base_path('Dockerfile.vercel'));
        $this->assertFileExists(base_path('Caddyfile'));
    }
}
