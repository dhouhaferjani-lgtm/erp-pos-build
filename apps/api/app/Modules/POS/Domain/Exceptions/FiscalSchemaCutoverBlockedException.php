<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a fiscal-schema cutover attempt is blocked.
 *
 * Gate checks (in order):
 * 1. pos.fiscal_schema_cutover permission — returns 403 to the caller.
 * 2. No open shift on the terminal.
 * 3. No un-Z-reported fiscalized receipts.
 * 4. Empty offline-sync queue (force-drain policy per Codex review 3 Finding C).
 */
final class FiscalSchemaCutoverBlockedException extends RuntimeException
{
    /**
     * Machine-readable reason code, suitable for frontend i18n key lookup.
     *
     * Values:
     *   - open_shift:           The terminal has an open shift that must be closed first.
     *   - unzreported_receipts: The terminal has fiscalized receipts that have not been
     *                           covered by a Z-report yet.
     *   - pending_sync:         The terminal has offline receipts waiting to be synced.
     *   - already_at_v3:        The terminal is already at fiscal_schema_version=3.
     */
    public readonly string $reason;

    private function __construct(string $message, string $reason)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public static function openShift(string $terminalId): self
    {
        return new self(
            "Terminal {$terminalId} has an open shift. Close the shift before cutting over to v3.",
            'open_shift',
        );
    }

    public static function unzreportedReceipts(string $terminalId): self
    {
        return new self(
            "Terminal {$terminalId} has fiscalized receipts that have not been covered by a Z-report yet.",
            'unzreported_receipts',
        );
    }

    public static function pendingSync(string $terminalId): self
    {
        return new self(
            "Terminal {$terminalId} has pending offline receipts that must sync before cutting over to v3.",
            'pending_sync',
        );
    }

    public static function alreadyAtV3(string $terminalId): self
    {
        return new self(
            "Terminal {$terminalId} is already at fiscal_schema_version=3.",
            'already_at_v3',
        );
    }
}
