<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\DTOs;

use App\Modules\POS\Domain\Enums\ShiftCashMovementSource;

/**
 * The expected cash in a shift's drawer, WITH the terms it was built from.
 *
 * The breakdown is not decoration. This number is written to
 * `pos_shifts.expected_cash`, and `Nf525DataProvider::mapShift()` exports it to
 * the NF525 JET as `EspecesAttendues` inside a `FERMETURE_CAISSE`
 * (`Nf525XmlBuilder::addTechnicalEvents()`). A single scalar gives an operator
 * no way to tell "the drawer really held the float" from "the movement query
 * found nothing" — and those two look identical in the column while meaning
 * opposite things. Every consumer that shows the figure to a human must be able
 * to show where it came from.
 */
final readonly class ShiftExpectedCashBreakdown
{
    /**
     * @param  numeric-string  $openingFloat  `pos_shifts.opening_cash`
     * @param  numeric-string  $cashSales  net CASH tendered on the shift's receipts (returns
     *                                     subtracted, change-due removed); always '0' for a v2
     *                                     shift, whose sales term rides inside $movementsNet
     * @param  numeric-string  $movementsNet  signed sum of the shift's drawer movements
     * @param  numeric-string  $expectedCash  openingFloat + cashSales + movementsNet, at $scale
     * @param  int  $movementCount  how many movement rows the sum is built from — 0 means
     *                              "none found", which is a fact worth printing
     */
    public function __construct(
        public string $openingFloat,
        public string $cashSales,
        public string $movementsNet,
        public string $expectedCash,
        public ShiftCashMovementSource $movementSource,
        public int $movementCount,
        public string $currencyCode,
        public int $scale,
    ) {}

    /**
     * Is the derived figure negative? A negative drawer is physically
     * impossible, and `pos_shifts_positive_amounts` refuses to store one, so
     * every caller must decide what to do BEFORE it tries to write.
     */
    public function isNegative(): bool
    {
        return bccomp($this->expectedCash, '0', $this->scale) < 0;
    }

    /**
     * One line an operator can read, naming every term.
     */
    public function describe(): string
    {
        return sprintf(
            'opening float %s + cash sales %s + movements %s (%d row(s) from %s) = %s %s',
            $this->openingFloat,
            $this->cashSales,
            $this->movementsNet,
            $this->movementCount,
            $this->movementSource->tableName(),
            $this->expectedCash,
            $this->currencyCode,
        );
    }
}
