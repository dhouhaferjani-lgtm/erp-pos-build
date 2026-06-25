import { describe, it, expect } from 'vitest'
import { buildProductPayload } from '../productPayload'
import type { ProductFormData } from '../ProductForm'

function makeFormData(overrides: Partial<ProductFormData> = {}): ProductFormData {
  return {
    name: 'Café Express 250g',
    sku: 'SKU-CAFE-001',
    type: null,
    is_physical: true,
    is_active_for_ecommerce: false,
    unit_id: null,
    category_id: null,
    description: '',
    sale_price: '12.500',
    purchase_price: '',
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

  it('includes type in the payload when set', () => {
    const payload = buildProductPayload(makeFormData({ type: 'part' }), { isParapharmacy: false })
    expect(payload.type).toBe('part')
  })

  it('includes null type in the payload when unset', () => {
    const payload = buildProductPayload(makeFormData({ type: null }), { isParapharmacy: false })
    expect(payload.type).toBeNull()
  })

  it('includes unit_id in the payload when set', () => {
    const payload = buildProductPayload(
      makeFormData({ unit_id: 'unit-uuid-abc' }),
      { isParapharmacy: false },
    )
    expect(payload.unit_id).toBe('unit-uuid-abc')
  })

  it('includes null unit_id in the payload when unset', () => {
    const payload = buildProductPayload(makeFormData({ unit_id: null }), { isParapharmacy: false })
    expect(payload.unit_id).toBeNull()
  })

  it('includes is_active_for_ecommerce in the payload', () => {
    const payload = buildProductPayload(
      makeFormData({ is_active_for_ecommerce: true }),
      { isParapharmacy: false },
    )
    expect(payload.is_active_for_ecommerce).toBe(true)
  })

  it('sets is_physical=false when type is service', () => {
    // The derive happens in the form (not in buildProductPayload itself); this
    // test asserts the form value flows through the payload unchanged.
    const payload = buildProductPayload(
      makeFormData({ type: 'service', is_physical: false }),
      { isParapharmacy: false },
    )
    expect(payload.type).toBe('service')
    expect(payload.is_physical).toBe(false)
  })

  it('sets is_physical=true when type is part', () => {
    const payload = buildProductPayload(
      makeFormData({ type: 'part', is_physical: true }),
      { isParapharmacy: false },
    )
    expect(payload.type).toBe('part')
    expect(payload.is_physical).toBe(true)
  })

  it('sets is_physical=true when type is consumable', () => {
    const payload = buildProductPayload(
      makeFormData({ type: 'consumable', is_physical: true }),
      { isParapharmacy: false },
    )
    expect(payload.type).toBe('consumable')
    expect(payload.is_physical).toBe(true)
  })

  it('includes purchase_price in the payload when provided', () => {
    const payload = buildProductPayload(
      makeFormData({ purchase_price: '60.000' }),
      { isParapharmacy: false },
    )
    expect(payload.purchase_price).toBe('60.000')
  })

  it('includes empty purchase_price in the payload (default state)', () => {
    const payload = buildProductPayload(makeFormData(), { isParapharmacy: false })
    expect(payload).toHaveProperty('purchase_price')
    expect(payload.purchase_price).toBe('')
  })
})
