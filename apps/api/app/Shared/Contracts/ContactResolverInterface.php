<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Interface for contact identity resolution used by other modules.
 *
 * Module boundaries are sacred (CLAUDE.md rule 6): cross-module communication
 * ONLY via interfaces. The sibling of `PartnerServiceInterface`, added for the
 * same reason — the POS fiscal projector writes `pos_receipts.contact_id`, a
 * `foreignUuid(...)->constrained('contacts')` column, from a sealed device
 * payload value that the fiscal validator accepts as an arbitrary non-empty
 * string at every event version.
 */
interface ContactResolverInterface
{
    /**
     * Resolve an existing contact within the caller's explicit scope.
     *
     * Queue consumers must supply tenant and company directly; this method
     * never relies on request-bound company context (CLAUDE.md rule 20).
     * Soft-deleted contacts remain resolvable because an immutable fiscal
     * receipt may arrive after the contact was archived.
     *
     * @return string|null The contact id when it exists in scope, else null.
     */
    public function resolveScopedContactId(
        string $tenantId,
        string $companyId,
        string $contactId,
    ): ?string;
}
