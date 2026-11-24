<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts every request without a workspace left over from an earlier one
 * (tests and long-running workers reuse the container).
 */
class ForgetCurrentWorkspace
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->forget();

        return $next($request);
    }

    public function terminate(): void
    {
        $this->current->forget();
    }
}
