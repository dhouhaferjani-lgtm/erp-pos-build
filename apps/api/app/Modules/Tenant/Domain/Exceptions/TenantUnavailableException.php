<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * P1-4 (Codex 2026-05-25): fail-closed signal for the pre-auth tenancy resolver.
 *
 * When DB-per-tenant mode is active and a request carries a present tenant source
 * whose tenant database/schema cannot be initialized, the resolver MUST NOT let
 * the request continue on the un-switched default connection (querying or
 * authenticating against the wrong DB). It throws this exception, which renders
 * as 503 Service Unavailable BEFORE auth:sanctum runs.
 *
 * In Phase 0a single-schema mode this is never thrown — the resolver returns a
 * benign false (the DB switch is intentionally a no-op).
 */
class TenantUnavailableException extends HttpException
{
    public function __construct(string $message = 'Tenant is temporarily unavailable.', ?Throwable $previous = null)
    {
        parent::__construct(503, $message, $previous);
    }
}
