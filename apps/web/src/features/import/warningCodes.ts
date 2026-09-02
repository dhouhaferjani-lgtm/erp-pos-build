type ImportWarningCode = App.Modules.Import.Domain.Enums.ImportWarningCode

export const WARNING_TRANSLATION_KEYS = {
  price_conflict: true,
  margin_without_cost: true,
  balance_not_posted: true,
  opening_failed: true,
  quantity_ignored_service: true,
  qty_without_cost: true,
  expiry_in_past: true,
  expiry_conflict_existing_lot: true,
  expiry_ignored_not_batch_tracked: true,
  expiry_ignored_no_default_lot: true,
  category_matched_by_slug: true,
  category_created: true,
  category_restored: true,
  sku_generated: true,
  code_generated: true,
  matched_by_name: true,
  duplicate_in_file: true,
  preview_drift: true,
  opening_skipped_existing: true,
  opening_corrected: true,
  unit_defaulted: true,
  location_not_supplied: true,
  location_code_unknown: true,
  location_unresolved: true,
  multi_location: true,
  barcode_float_corruption_suspected: true,
  numeric_normalized: true,
  opening_exists: true,
} as const satisfies Record<ImportWarningCode, true>

export const KNOWN_WARNING_CODES: ReadonlySet<string> = new Set(Object.keys(WARNING_TRANSLATION_KEYS))
