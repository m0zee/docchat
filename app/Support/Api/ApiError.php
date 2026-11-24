<?php

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The one error shape of the public API:
 * {"error": {"code": "...", "message": "...", "details": {...}}}
 */
class ApiError
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    public static function fromException(Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof ValidationException => self::response(
                422, 'validation_failed', 'The request is not valid.', ['fields' => $exception->errors()],
            ),
            $exception instanceof AuthenticationException => self::response(401, 'unauthenticated', 'A valid API key is required.'),
            $exception instanceof AuthorizationException => self::response(403, 'forbidden', $exception->getMessage() ?: 'This key is not allowed to do that.'),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => self::response(404, 'not_found', 'Not found.'),
            $exception instanceof ThrottleRequestsException => self::response(
                429, 'rate_limited', 'Too many requests. Try again later.',
                ['retry_after' => (int) ($exception->getHeaders()['Retry-After'] ?? 60)],
                array_map('strval', $exception->getHeaders()),
            ),
            $exception instanceof HttpExceptionInterface => self::response(
                $exception->getStatusCode(), 'http_error', $exception->getMessage() ?: 'Request failed.', [], $exception->getHeaders(),
            ),
            default => self::response(500, 'server_error', config('app.debug') ? $exception->getMessage() : 'Something went wrong.'),
        };
    }
}
