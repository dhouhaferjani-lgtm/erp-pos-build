type ImportErrorCode = App.Modules.Import.Domain.Enums.ImportErrorCode

export const ERROR_TRANSLATION_KEYS = {
  units_not_seeded: true,
  unit_unknown: true,
  unit_ambiguous: true,
  unit_default_missing: true,
  barcode_ambiguous: true,
  barcode_identity_conflict: true,
  product_not_found: true,
  partner_not_found: true,
  sku_held_by_deleted_product: true,
  vat_held_by_deleted_partner: true,
  duplicate_sku_in_company: true,
  invalid_number: true,
  validation_failed: true,
  internal_error: true,
  worker_lost: true,
} as const satisfies Record<ImportErrorCode, true>
