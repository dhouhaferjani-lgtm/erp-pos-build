<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Classifier for RETRYABLE database faults — deadlocks, serialization failures
 * and the PostgreSQL "current transaction is aborted" state.
 *
 * WHY THIS EXISTS (DPA V10 gate C2)
 *
 * A projector may never REJECT an already-signed fiscal event, which makes
 * `catch (\Throwable)` around a contained side effect look like the right
 * pattern. It is — for DOMAIN failures. It is catastrophic for concurrency
 * faults, because of how Laravel handles a nested transaction:
 *
 *   Illuminate\Database\Concerns\ManagesTransactions::transaction()
 *     if ($this->causedByConcurrencyError($e) && $this->transactions > 1) {
 *         $this->transactions--;                 // no ROLLBACK TO SAVEPOINT
 *         throw new DeadlockException(...);
 *     }
 *     $this->rollBack();                         // <- NOT reached
 *
 * So a deadlock inside a SAVEPOINT never actually rolls the savepoint back. On
 * PostgreSQL the ENCLOSING transaction is then in aborted state (SQLSTATE
 * 25P02): every subsequent statement fails, and if the caller swallows the
 * exception and returns normally, Laravel issues `COMMIT`, PostgreSQL silently
 * converts it to `ROLLBACK` and reports success. The visible result is an
 * entire projection/return silently lost while its bookkeeping row says
 * "applied" — and no retry, because nothing threw.
 *
 * A retryable infrastructure fault is NOT the projector rejecting a signed
 * event; the job's own retry is the correct handling. Every `catch (\Throwable)`
 * that wraps a lock-taking block must therefore re-throw when this returns true.
 */
final class ConcurrencyFault
{
    use DetectsConcurrencyErrors;

    /**
     * SQLSTATE classes that mean "retry the whole unit of work".
     *
     * 40001 serialization_failure
     * 40P01 deadlock_detected
     * 25P02 in_failed_sql_transaction — the aborted-transaction state a nested
     *       concurrency fault leaves behind; swallowing THIS is what silently
     *       converts the outer COMMIT into a ROLLBACK.
     * 25006 read_only_sql_transaction — mid-write failover to a replica.
     */
    private const RETRYABLE_SQL_STATES = ['40001', '40P01', '25P02', '25006'];

    /**
     * True when the throwable (or any exception in its `previous` chain) is a
     * retryable database concurrency/abort fault.
     */
    public static function isRetryable(Throwable $e): bool
    {
        return (new self)->classify($e);
    }

    private function classify(Throwable $e): bool
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof DeadlockException) {
                return true;
            }

            if ($this->causedByConcurrencyError($cursor)) {
                return true;
            }

            if ($cursor instanceof \PDOException && $this->hasRetryableSqlState($cursor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The SQLSTATE lives in TWO places on a wrapped driver error:
     * `errorInfo[0]` (copied verbatim by `QueryException::__construct`) and the
     * exception code (`$this->code = $previous->getCode()`). Read both — a
     * driver or a test double may populate only one.
     */
    private function hasRetryableSqlState(\PDOException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if (is_string($sqlState) && in_array($sqlState, self::RETRYABLE_SQL_STATES, true)) {
            return true;
        }

        return in_array((string) $e->getCode(), self::RETRYABLE_SQL_STATES, true);
    }
}
