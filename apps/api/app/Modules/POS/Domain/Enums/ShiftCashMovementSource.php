<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

/**
 * Which table a shift's cash-drawer MOVEMENTS were read from when its expected
 * cash was derived.
 *
 * The two are not interchangeable and the choice is not a preference — it is
 * decided by the terminal's `fiscal_schema_version`:
 *
 *  - a v3 (device-authoritative) shift books every movement as a fiscal event
 *    that projects into `pos_z_session_events`. It writes NO
 *    `pos_cash_drawer_operations` row at all (LEDGER ES-05), which is why
 *    `Nf525DataProvider` reads `ZSessionEvent` as the CANONICAL cash-drawer
 *    operation set (`Nf525DataProvider.php:263-272`, `:1343-1357`);
 *  - a v2/legacy shift books movements server-side through `CashDrawerService`
 *    into `pos_cash_drawer_operations`, which is what
 *    `CashDrawerService::calculateExpectedCash()` — and therefore the v2 Z
 *    report — sums.
 *
 * Reading the wrong one does not fail: it silently returns a number built from
 * an empty movement set, which is exactly the defect this enum exists to make
 * impossible to reintroduce unnoticed.
 */
enum ShiftCashMovementSource: string
{
    case ZSessionEvents = 'z_session_events';

    case CashDrawerOperations = 'cash_drawer_operations';

    /**
     * The table the movements were read from, for operator-facing output.
     */
    public function tableName(): string
    {
        return match ($this) {
            self::ZSessionEvents => 'pos_z_session_events',
            self::CashDrawerOperations => 'pos_cash_drawer_operations',
        };
    }
}
