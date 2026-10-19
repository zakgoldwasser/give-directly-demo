<?php

namespace App\Http\Middleware;

use App\Models\GuestWorkspace;
use App\Models\WorkspaceOperation;
use App\Services\Operations\OperationRejectedException;
use App\Services\Operations\ProcessingDeadline;
use App\Services\Operations\ProcessingDeadlineExceeded;
use App\Services\Operations\WorkspaceOperationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BoundedWorkspaceOperation
{
    public function __construct(
        private readonly WorkspaceOperationService $operations,
        private readonly ProcessingDeadline $deadline,
    ) {}

    public function handle(Request $request, Closure $next, string $operationType): Response
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey === null) {
            return $this->errorResponse(
                $request,
                'INVALID_IDEMPOTENCY_KEY',
                'The idempotency key must contain 8 to 128 safe characters.',
                (string) Str::uuid(),
                422,
            );
        }

        try {
            $claim = $this->operations->claim(
                $workspace,
                $operationType,
                $idempotencyKey,
                $this->inputFingerprint($request),
            );
        } catch (OperationRejectedException $exception) {
            $retryAfter = $exception->operation->lease_expires_at?->diffInSeconds(now(), true);

            return $this->errorResponse(
                $request,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->operation->trace_id,
                409,
                $retryAfter === null ? null : max(1, (int) ceil($retryAfter)),
            );
        }

        if ($claim->replayed) {
            return $this->replay($request, $claim->operation);
        }

        $operation = $claim->operation;
        $request->attributes->set('operation_trace_id', $operation->trace_id);
        $this->deadline->activate($operation->trace_id);

        try {
            $response = $next($request);

            if ($response->getStatusCode() >= 400
                || ($response instanceof RedirectResponse && $request->session()->has('errors'))) {
                $this->operations->fail($operation, 'REQUEST_REJECTED');
                $response->headers->set('X-Trace-Id', $operation->trace_id);

                return $response;
            }

            $this->deadline->checkpoint();
            $this->operations->complete(
                $operation,
                $response->getStatusCode(),
                $response->headers->get('Location'),
                $response instanceof JsonResponse ? $response->getData(true) : null,
            );
            $response->headers->set('X-Trace-Id', $operation->trace_id);

            return $response;
        } catch (ProcessingDeadlineExceeded $exception) {
            $this->operations->fail($operation, 'DEADLINE_EXCEEDED');
            Log::warning('Workspace operation exceeded its processing deadline.', [
                'operation_id' => $operation->id,
                'operation_type' => $operation->operation_type,
                'trace_id' => $exception->traceId,
                'exception' => $exception,
            ]);

            return $this->errorResponse(
                $request,
                'PROCESSING_DEADLINE_EXCEEDED',
                'The operation took too long and was stopped. It is safe to retry.',
                $exception->traceId,
                503,
            );
        } catch (ValidationException|HttpExceptionInterface $exception) {
            $this->operations->fail($operation, 'REQUEST_REJECTED');

            throw $exception;
        } catch (Throwable $exception) {
            $this->operations->fail($operation, 'PROCESSING_FAILED');
            Log::error('Bounded workspace operation failed.', [
                'operation_id' => $operation->id,
                'operation_type' => $operation->operation_type,
                'trace_id' => $operation->trace_id,
                'exception' => $exception,
            ]);

            return $this->errorResponse(
                $request,
                'PROCESSING_FAILED',
                'The operation could not be completed. It is safe to retry.',
                $operation->trace_id,
                500,
            );
        } finally {
            $this->deadline->clear();
        }
    }

    private function idempotencyKey(Request $request): ?string
    {
        $provided = $request->header('Idempotency-Key', $request->input('idempotency_key'));

        if ($provided === null) {
            return (string) Str::uuid();
        }

        if (! is_string($provided)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/', $provided) !== 1) {
            return null;
        }

        return $provided;
    }

    private function inputFingerprint(Request $request): string
    {
        $input = $request->request->all();
        unset($input['_token'], $input['idempotency_key']);
        $files = [];

        foreach ($request->allFiles() as $name => $file) {
            if ($file instanceof UploadedFile && $file->getRealPath() !== false) {
                $files[$name] = [
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'size' => $file->getSize(),
                ];
            }
        }

        $route = [];

        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            $route[$name] = is_object($value) && method_exists($value, 'getRouteKey')
                ? $value->getRouteKey()
                : $value;
        }

        return hash('sha256', json_encode($this->canonicalize([
            'route' => $request->route()?->getName(),
            'parameters' => $route,
            'input' => $input,
            'files' => $files,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function replay(Request $request, WorkspaceOperation $operation): Response
    {
        $status = $operation->response_status ?? 200;

        if ($operation->response_payload !== null) {
            $response = response()->json($operation->response_payload, $status);
        } elseif ($operation->response_location !== null) {
            $response = redirect()->to($operation->response_location, $status);
        } else {
            $response = redirect()->route('try.show');
        }

        $response->headers->set('X-Trace-Id', $operation->trace_id);
        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    private function errorResponse(
        Request $request,
        string $code,
        string $message,
        string $traceId,
        int $status,
        ?int $retryAfter = null,
    ): Response {
        $data = [
            'code' => $code,
            'message' => $message,
            'trace_id' => $traceId,
        ];
        $response = $request->expectsJson()
            ? response()->json(['error' => $data], $status)
            : response()->view('public.operation-error', ['error' => $data], $status);
        $response->headers->set('X-Trace-Id', $traceId);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
