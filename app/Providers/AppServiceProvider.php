<?php

namespace App\Providers;

use App\Services\Operations\ProcessingDeadline;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessingDeadline::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('workspace-starts', function (Request $request): array {
            return $this->limits(
                $request,
                'workspace-starts',
                (int) config('reconciliation.workspace_start_limits.per_session', 5),
                (int) config('reconciliation.workspace_start_limits.per_ip', 5),
            );
        });

        RateLimiter::for('workspace-work', function (Request $request): array {
            return $this->limits(
                $request,
                'workspace-work',
                (int) config('reconciliation.workspace_work_limits.per_session', 10),
                (int) config('reconciliation.workspace_work_limits.per_ip', 10),
            );
        });
    }

    /**
     * @return array<int, Limit>
     */
    private function limits(Request $request, string $scope, int $sessionLimit, int $ipLimit): array
    {
        return [
            Limit::perMinute(max(1, $sessionLimit))
                ->by($scope.':session:'.hash('sha256', $request->session()->getId())),
            Limit::perMinute(max(1, $ipLimit))
                ->by($scope.':ip:'.hash('sha256', $this->clientIp($request))),
        ];
    }

    private function clientIp(Request $request): string
    {
        if ((bool) config('app.running_on_vercel')) {
            $vercelIp = $request->header('x-vercel-forwarded-for');

            if (is_string($vercelIp) && filter_var($vercelIp, FILTER_VALIDATE_IP) !== false) {
                return $vercelIp;
            }
        }

        return (string) $request->ip();
    }
}
