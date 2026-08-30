<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

/**
 * G-12 created this shared enum under spec §3.1d's earliest-lane rule.
 * G-6a, G-4, G-2, G-8, G-1, and G-5 add their own cases. isJobLevel()
 * must remain an exhaustive match with no default so every new case makes
 * its row-level versus job-level decision explicitly.
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

    public function isJobLevel(): bool
    {
        return match ($this) {
            self::UnitsNotSeeded => true,
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
