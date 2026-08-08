<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

/**
 * The restock location could not be resolved for a goods-bearing return decision.
 *
 * Plan CF CF-D11 / fiscal gate N-C2. `receiveStockBack()` resolves a line's location
 * as line → document → **throws**, and invoices are not required to carry a location
 * at either level (they move no stock, and validation makes `location_id` nullable on
 * both the document and the line). Revision 1 of the plan copied `location_id` from
 * the invoice line and stopped, leaving two unacceptable outcomes and choosing
 * neither: a hard `\DomainException` inside the outer transaction — rolling the CANCEL
 * back and surfacing as an untyped `{error: <string>}` — or an invented fallback that
 * restocks goods into a location they never left, silently, in one click.
 *
 * The location therefore comes from the CONFIRMED DELIVERY NOTE (line location, else
 * the note's), which also makes the delivery note the justifying document for the
 * movement — the document-per-action principle applied to the restock itself. When
 * nothing resolves, this typed refusal is raised. **Never** fall back to the invoice's
 * location, the document location, or a company default.
 */
final class ReturnLocationUnresolvedException extends DomainException
{
    public const CODE = 'RETURN_LOCATION_UNRESOLVED';

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
    ) {
        parent::__construct(
            "Cannot record a goods return for invoice {$invoiceNumber}: no confirmed delivery note identifies "
            .'the location the goods left from, and guessing one would create stock where it never was.'
        );
    }
}
