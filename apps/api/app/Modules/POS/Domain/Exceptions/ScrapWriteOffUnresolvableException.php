<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

/**
 * The SCRAP destruction leg of a POS return cannot be valued or recorded.
 *
 * WHY IT MUST THROW (DPA V10 gate C1)
 *
 * A SCRAP return is TWO legs — re-entry (`+qty`) then destruction (`−qty`) — and
 * they are only meaningful TOGETHER. If the destruction leg quietly declines
 * (the pre-fix code `return`ed null when the product was unresolvable, e.g.
 * soft-deleted between the sale and the return), the enclosing SAVEPOINT commits
 * with ONLY the re-entry leg applied: a permanent `+qty` restock of goods the
 * device said were destroyed, authored silently, with regulated/destroyed items
 * becoming sellable again.
 *
 * Throwing is therefore load-bearing, not defensive noise: it is what makes the
 * pair ATOMIC. Both call paths wrap the pair in a savepoint and treat this
 * exception as "record neither leg" — the quantity falls back to the pre-return
 * figure and the refund itself still completes.
 */
final class ScrapWriteOffUnresolvableException extends \RuntimeException
{
    public static function forProduct(string $productId, string $returnReceiptId, string $reason): self
    {
        return new self(
            "Cannot record the SCRAP destruction leg for product {$productId} "
            ."on return receipt {$returnReceiptId}: {$reason}. Both legs of the "
            .'scrap pair are rolled back so no phantom restock survives.'
        );
    }
}
