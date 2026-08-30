<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

enum ImportWarningCode: string
{
    case PriceConflict = 'price_conflict';
    case MarginWithoutCost = 'margin_without_cost';
    case BalanceNotPosted = 'balance_not_posted';
    case OpeningFailed = 'opening_failed';
    case QuantityIgnoredService = 'quantity_ignored_service';
    case QtyWithoutCost = 'qty_without_cost';
    case ExpiryInPast = 'expiry_in_past';
    case ExpiryConflictExistingLot = 'expiry_conflict_existing_lot';
    case ExpiryIgnoredNotBatchTracked = 'expiry_ignored_not_batch_tracked';
    case ExpiryIgnoredNoDefaultLot = 'expiry_ignored_no_default_lot';
    case CategoryMatchedBySlug = 'category_matched_by_slug';
    case CategoryCreated = 'category_created';
    case CategoryRestored = 'category_restored';
    case SkuGenerated = 'sku_generated';
    case CodeGenerated = 'code_generated';
    case MatchedByName = 'matched_by_name';
    case DuplicateInFile = 'duplicate_in_file';
    case PreviewDrift = 'preview_drift';
    case OpeningSkippedExisting = 'opening_skipped_existing';
    case OpeningCorrected = 'opening_corrected';
    case UnitDefaulted = 'unit_defaulted';
    case LocationNotSupplied = 'location_not_supplied';
    case LocationCodeUnknown = 'location_code_unknown';
    case LocationUnresolved = 'location_unresolved';
    case OpeningExists = 'opening_exists';
}
