<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\PartnerServiceInterface;

/**
 * Application service for partner operations.
 *
 * Exposes partner functionality to other modules through the PartnerServiceInterface.
 */
final class PartnerService implements PartnerServiceInterface
{
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
    ): ?array {
        $partner = Partner::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($vatNumber !== null, fn ($q) => $q->where('vat_number', $vatNumber))
            ->when($vatNumber === null, fn ($q) => $q->where('name', $name))
            ->first();

        if ($partner === null) {
            return null;
        }

        return [
            'id' => $partner->id,
            'type' => $partner->type->value,
        ];
    }

    /**
     * Create or update a partner with smart type merging.
     *
     * If a partner exists with a different type, the result will be 'both'.
     *
     * @param  array<string, mixed>  $data  Partner data
     * @return string The partner ID
     */
    public function upsertWithTypeMerge(
        string $tenantId,
        string $companyId,
        array $data
    ): string {
        $vatNumber = ! empty($data['vat_number']) ? $data['vat_number'] : null;
        $newType = PartnerType::from($data['type']);

        // Find existing partner
        $existing = Partner::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($vatNumber !== null, fn ($q) => $q->where('vat_number', $vatNumber))
            ->when($vatNumber === null, fn ($q) => $q->where('name', $data['name']))
            ->first();

        // Smart type merging: customer + supplier = both
        $finalType = $newType;
        if ($existing !== null && $existing->type !== $newType) {
            if ($existing->type === PartnerType::Both || $newType === PartnerType::Both) {
                $finalType = PartnerType::Both;
            } else {
                $finalType = PartnerType::Both;
            }
        }

        $searchCriteria = $vatNumber !== null
            ? ['tenant_id' => $tenantId, 'company_id' => $companyId, 'vat_number' => $vatNumber]
            : ['tenant_id' => $tenantId, 'company_id' => $companyId, 'name' => $data['name']];

        $partner = Partner::updateOrCreate(
            $searchCriteria,
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'name' => $data['name'],
                'type' => $finalType,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'vat_number' => $vatNumber,
            ]
        );

        return $partner->id;
    }
}
