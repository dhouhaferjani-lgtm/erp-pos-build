<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

/**
 * Shared import refusal vocabulary co-created by G-12 and G-6a under §3.1d.
 *
 * G-12 created units_not_seeded; G-6a adds worker_lost. Later lanes G-4,
 * G-2, G-5, G-8, and G-1 add the row-level cases listed in §3.2.1. The
 * exhaustive match deliberately has no default so every added case must make
 * its job-level versus row-level decision explicit.
 */
enum ImportErrorCode: string
{
    case UnitsNotSeeded = 'units_not_seeded';
    case UnitUnknown = 'unit_unknown';
    case UnitAmbiguous = 'unit_ambiguous';
    case UnitDefaultMissing = 'unit_default_missing';
    case BarcodeAmbiguous = 'barcode_ambiguous';
    case ProductNotFound = 'product_not_found';
    case PartnerNotFound = 'partner_not_found';
    case SkuHeldByDeletedProduct = 'sku_held_by_deleted_product';
    case VatHeldByDeletedPartner = 'vat_held_by_deleted_partner';
    case DuplicateSkuInCompany = 'duplicate_sku_in_company';
    case ValidationFailed = 'validation_failed';
    case InternalError = 'internal_error';
    case WorkerLost = 'worker_lost';

    public function isJobLevel(): bool
    {
        return match ($this) {
            self::UnitsNotSeeded,
            self::WorkerLost => true,
            self::UnitUnknown,
            self::UnitAmbiguous,
            self::UnitDefaultMissing,
            self::BarcodeAmbiguous,
            self::ProductNotFound,
            self::PartnerNotFound,
            self::SkuHeldByDeletedProduct,
            self::VatHeldByDeletedPartner,
            self::DuplicateSkuInCompany,
            self::ValidationFailed,
            self::InternalError => false,
        };
    }
}
