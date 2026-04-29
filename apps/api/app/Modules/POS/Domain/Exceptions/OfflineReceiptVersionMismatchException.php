<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use Exception;

/**
 * Thrown when an offline payload's fiscal_schema_version does not match
 * the terminal's configured fiscal_schema_version.
 *
 * Design: the "force-drain" cutover policy requires that a terminal cannot
 * switch to v3 while it still has unsynced offline receipts produced under
 * v2. Any v2 payload arriving against a v3 terminal (or vice-versa) is
 * categorically refused to prevent chain corruption.
 */
final class OfflineReceiptVersionMismatchException extends Exception
{
    public static function payloadV2AgainstV3Terminal(string $terminalId): self
    {
        return new self(
            sprintf(
                'Terminal %s operates fiscal schema v3 but the offline payload declares v2. '.
                'Drain all unsynced offline receipts before cutting over to v3.',
                $terminalId
            )
        );
    }

    public static function versionMismatch(string $terminalId, int $payloadVersion, int $terminalVersion): self
    {
        return new self(
            sprintf(
                'Terminal %s fiscal schema version mismatch: payload declares v%d but terminal requires v%d. '.
                'Ensure the client is running the correct firmware version for this terminal.',
                $terminalId,
                $payloadVersion,
                $terminalVersion
            )
        );
    }
}
