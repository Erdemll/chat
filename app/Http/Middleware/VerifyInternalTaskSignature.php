<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyInternalTaskSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('internal_tasks.secret');
        $timestamp = $request->header('X-Task-Timestamp');
        $nonce = $request->header('X-Task-Nonce');
        $signature = $request->header('X-Task-Signature');
        $skew = max(1, (int) config('internal_tasks.max_skew_seconds'));

        if (! is_string($secret) || trim($secret) === ''
            || ! is_string($timestamp) || ! preg_match('/\A[0-9]{1,12}\z/', $timestamp)
            || ! is_string($nonce) || ! preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $nonce)
            || ! is_string($signature) || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
            || abs(now()->getTimestamp() - (int) $timestamp) > $skew) {
            return $this->unauthorized();
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized();
        }

        $driver = config('cache.stores.'.config('cache.default').'.driver');
        if (! app()->runningUnitTests() && in_array($driver, ['array', 'null'], true)) {
            return $this->unauthorized();
        }

        try {
            if (! Cache::add('internal_task_nonce:'.hash('sha256', $nonce), true, 2 * $skew + 60)) {
                return $this->unauthorized();
            }
        } catch (Throwable $exception) {
            Log::warning('Internal task nonce storage failed', ['exception_type' => $exception::class]);

            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
