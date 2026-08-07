<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Recurring expenses pointed at the partner as supplier.
 *
 * `expense_recurrence_templates.partner_id` is SET NULL. A template is not
 * history — it is a standing instruction that keeps GENERATING expenses on
 * schedule. Leaving one attached to a soft-deleted supplier means new
 * expense rows would keep being created for a partner nobody can see.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class ExpensePartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('expense_recurrence_templates'),
        ];
    }
}
