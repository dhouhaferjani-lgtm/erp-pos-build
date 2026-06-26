<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Enums;

/**
 * Controls when an AP bill may be posted relative to goods receipt.
 *
 * Phase 1 supports ONLY `received` (receipt-first). `ordered` is reserved
 * for Phase 2 (invoice-first flow) and will throw a DomainException if
 * a policy resolves to it in Phase 1 code-paths.
 */
enum BillControlMode: string
{
    /** Bill may only be posted after goods receipt (GR-first). Phase 1 default. */
    case Received = 'received';

    /**
     * Bill posted immediately on PO creation, before goods arrive (invoice-first).
     *
     * RESERVED — Phase 2 only. The resolver throws a DomainException if this
     * mode is active in Phase 1 flows.
     */
    case Ordered = 'ordered';
}
