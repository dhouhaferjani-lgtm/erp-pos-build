<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\InventoryValuationMode;
use RuntimeException;

/**
 * A company resolved to an inventory valuation mode this system does not
 * implement (DPA Wave 3, D-14 / T9).
 *
 * Raised — never swallowed — because the alternative is worse: under periodic
 * valuation there is no COGS at exit, so every entry the Wave-3 seam would post
 * is a mis-statement. Failing closed keeps the ledger silent instead of wrong.
 *
 * It is a `RuntimeException` subclass so existing broad catch sites behave as
 * before, and it carries the mode and company so the message is actionable
 * without a database round-trip.
 */
final class UnsupportedValuationModeException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $companyId,
        public readonly InventoryValuationMode $mode,
    ) {
        parent::__construct($message);
    }

    public static function forCompany(string $companyId, InventoryValuationMode $mode): self
    {
        return new self(
            sprintf(
                'Company %s resolves to inventory valuation mode "%s", which is not implemented. '
                .'Only "%s" is supported. The mode is admitted by the schema so it can be enabled '
                .'without DDL, but the machinery does not exist yet — see DPA Wave 3, D-14.',
                $companyId,
                $mode->value,
                InventoryValuationMode::Perpetual->value,
            ),
            $companyId,
            $mode,
        );
    }
}
