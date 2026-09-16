<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JeffersonGoncalves\QueueWorker\Exceptions\MissingQueueTokenException;
use Symfony\Component\HttpFoundation\Response;

class VerifyQueueWorkerToken
{
    /**
     * Rejects any request that did not arrive over HTTPS while
     * queue-worker.require_https is on, refuses to process any request while
     * no token is configured, and rejects any request whose token does not
     * match using a constant-time comparison.
     *
     * @throws MissingQueueTokenException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('queue-worker.require_https', true) && ! $request->secure()) {
            abort(426, 'This endpoint requires HTTPS. Set QUEUE_WORKER_REQUIRE_HTTPS=false only when the transport is already private (loopback or an encrypted network), and check TrustProxies if TLS terminates at a proxy.');
        }

        $configured = (string) config('queue-worker.token');

        if ($configured === '') {
            throw MissingQueueTokenException::becauseTokenIsMissing();
        }

        $provided = (string) $request->header('X-Laravel-Queue-Token');

        if (! hash_equals($configured, $provided)) {
            abort(403, 'Invalid or missing X-Laravel-Queue-Token header.');
        }

        return $next($request);
    }
}
