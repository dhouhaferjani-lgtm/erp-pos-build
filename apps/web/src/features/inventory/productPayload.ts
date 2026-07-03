import type { ProductFormData } from './ProductForm'

/**
 * Shape POSTed/PATCHed to /products. Differs from the form state in three ways,
 * each fixing a confirmed CreateProductRequest mismatch:
 *
 *  - `tax_configuration_id` is renamed to `default_tax_configuration_id` — the
 *    only tax-config key the API validates/persists. Sending the form's name
 *    silently dropped the chosen tax configuration on create/update.
 *  - `tax_rate` is omitted — the form copies the tax configuration's 4-decimal
 *    `percentage_rate`, which the API rejects (max 2dp) and re-derives anyway
 *    from `default_tax_configuration_id`.
 *  - `parapharmacy_metadata` is included only for the parapharmacy vertical.
 *    The API authorizes it by vertical and 422-rejects it (even when empty) for
 *    any other business type, which blocked product creation for retail tenants.
 */
export interface ProductApiPayload
  extends Omit<
    ProductFormData,
    'tax_rate' | 'tax_configuration_id' | 'parapharmacy_metadata' | 'opening_qty' | 'opening_unit_cost'
  > {
  default_tax_configuration_id: string | null
  parapharmacy_metadata?: ProductFormData['parapharmacy_metadata']
  /** Included only on create when opening_qty > 0. */
  opening_qty?: string
  /** Included only on create when opening_qty > 0. */
  opening_unit_cost?: string
  /** Set only when creating from a found catalog lookup with a server-resolved local brand. */
  brand_id?: string
}

export function buildProductPayload(
  data: ProductFormData,
  opts: { isParapharmacy: boolean; suggestedBrandId?: string | null },
): ProductApiPayload {
  const {
    tax_rate: _taxRate,
    tax_configuration_id,
    parapharmacy_metadata,
    opening_qty: _openingQty,
    opening_unit_cost: _openingUnitCost,
    ...rest
  } = data

  const payload: ProductApiPayload = {
    ...rest,
    default_tax_configuration_id: tax_configuration_id,
  }

  // Vertical-gated: only the parapharmacy vertical may submit this block.
  if (opts.isParapharmacy) {
    payload.parapharmacy_metadata = parapharmacy_metadata
  }

  // Server-resolved local brand from a found catalog lookup.
  if (opts.suggestedBrandId) {
    payload.brand_id = opts.suggestedBrandId
  }

  return payload
}
