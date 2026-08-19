<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Billing;

use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Finite transaction retry boundary for delivery-note billing claims.
 *
 * Only PostgreSQL deadlock and serialization failures are retryable. Every
 * attempt starts a fresh transaction with bounded server-side waits. A third
 * retryable failure becomes the established attributed billing refusal, while
 * every unrelated database exception propagates unchanged.
 */
final class DeliveryNoteBillingConcurrencyRetrier
{
    private const MAX_RETRIES = 2;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(string $deliveryNoteId, Closure $operation): mixed
    {
        $retry = 0;

        while (true) {
            try {
                return $this->db->transaction(function () use ($operation): mixed {
                    if ($this->db instanceof Connection && $this->db->getDriverName() === 'pgsql') {
                        $this->db->statement("SET LOCAL lock_timeout = '5s'");
                        $this->db->statement("SET LOCAL statement_timeout = '30s'");
                    }

                    return $operation();
                });
            } catch (QueryException $exception) {
                if (! $this->isRetryable($exception)) {
                    throw $exception;
                }

                if ($retry >= self::MAX_RETRIES) {
                    throw new DeliveryNoteAlreadyClaimedException($deliveryNoteId, $exception);
                }

                $retry++;
                usleep(random_int(1_000, 5_000) * $retry);
            }
        }
    }

    private function isRetryable(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['40P01', '40001'], true);
    }
}
