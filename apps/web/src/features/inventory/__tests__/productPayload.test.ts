import { describe, it, expect } from 'vitest'
import { buildProductPayload } from '../productPayload'
import type { ProductFormData } from '../ProductForm'

function makeFormData(overrides: Partial<ProductFormData> = {}): ProductFormData {
  return {
    name: 'Café Express 250g',
    sku: 'SKU-CAFE-001',
    is_physical: true,
    category_id: null,
    description: '',
    sale_price: '12.500',
    // The form copies the selected tax configuration's 4-decimal percentage_rate
    // into tax_rate; the backend rejects >2dp, and the value is redundant.
    tax_rate: '19.0000',
    tax_configuration_id: 'cfg-uuid-123',
    unit: 'pcs',
    barcode: '',
    is_active: true,
    oem_numbers: [],
    cross_references: [],
    parapharmacy_metadata: {
      category: '',
      dosage_form: null,
      active_ingredients: [],
      usage_instructions: null,
      warnings: null,
      contraindications: null,
      minimum_age: null,
      age_restriction: null,
      requires_consultation: false,
      regulatory_code: null,
      storage_requirements: null,
    },
    requires_batch_tracking: false,
    default_shelf_life_days: null,
    ...overrides,
  }
}

describe('buildProductPayload', () => {
  it('renames tax_configuration_id to default_tax_configuration_id (the key the API persists)', () => {
    const payload = buildProductPayload(makeFormData(), { isParapharmacy: false })
    expect(payload).not.toHaveProperty('tax_configuration_id')
    expect(payload.default_tax_configuration_id).toBe('cfg-uuid-123')
  })

  it('drops the redundant 4-decimal tax_rate (backend resolves it from the tax config)', () => {
    const payload = buildProductPayload(makeFormData(), { isParapharmacy: false })
    expect(payload).not.toHaveProperty('tax_rate')
  })

  it('omits parapharmacy_metadata for a non-parapharmacy vertical (backend 422s on it)', () => {
    const payload = buildProductPayload(makeFormData(), { isParapharmacy: false })
    expect(payload).not.toHaveProperty('parapharmacy_metadata')
  })

  it('includes parapharmacy_metadata for a parapharmacy vertical', () => {
    const meta = {
      ...makeFormData().parapharmacy_metadata,
      category: 'supplements',
    }
    const payload = buildProductPayload(
      makeFormData({ parapharmacy_metadata: meta }),
      { isParapharmacy: true }
    )
    expect(payload.parapharmacy_metadata).toEqual(meta)
  })

  it('preserves core + harmless top-level fields', () => {
    const payload = buildProductPayload(makeFormData(), { isParapharmacy: false })
    expect(payload.name).toBe('Café Express 250g')
    expect(payload.sku).toBe('SKU-CAFE-001')
    expect(payload.sale_price).toBe('12.500')
    expect(payload.is_physical).toBe(true)
    expect(payload.oem_numbers).toEqual([])
    expect(payload.cross_references).toEqual([])
  })

  it('passes a null tax selection through as null default_tax_configuration_id', () => {
    const payload = buildProductPayload(
      makeFormData({ tax_configuration_id: null }),
      { isParapharmacy: false }
    )
    expect(payload.default_tax_configuration_id).toBeNull()
    expect(payload).not.toHaveProperty('tax_rate')
  })
})
