<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireConfiguredBearerToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $configKey): Response
    {
        $secret = config($configKey);

        if (! is_string($secret) || strlen($secret) < 16) {
            return new JsonResponse([
                'error' => [
                    'code' => 'ENDPOINT_NOT_CONFIGURED',
                    'message' => 'This operational endpoint is not configured.',
                ],
            ], 503);
        }

        $authorization = $request->header('Authorization');

        if (! is_string($authorization) || ! hash_equals('Bearer '.$secret, $authorization)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Valid bearer authorization is required.',
                ],
            ], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
