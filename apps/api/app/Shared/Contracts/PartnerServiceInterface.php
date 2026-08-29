<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Interface for partner operations used by other modules.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface PartnerServiceInterface
{
    /**
     * Resolve a customer-capable partner within the event's explicit scope.
     *
     * Queue consumers must supply tenant and company directly; this method
     * never relies on request-bound company context.
     */
    public function resolveScopedCustomerId(
        string $tenantId,
        string $companyId,
        string $customerId,
    ): ?string;

    /**
     * Find a partner by VAT number or name.
     *
     * @return array{id: string, type: string}|null Partner info or null if not found
     */
    public function findByVatOrName(
        string $tenantId,
        string $companyId,
        ?string $vatNumber,
        string $name
    ): ?array;

    /**
     * Create or update a partner with smart type merging.
     *
     * Matching precedence is company-scoped code, then VAT number, then name.
     *
     * @param  array<string, mixed>  $data  Partner data
     * @return string The partner ID
     */
    public function upsertWithTypeMerge(
        string $tenantId,
        string $companyId,
        array $data
    ): string;
}
