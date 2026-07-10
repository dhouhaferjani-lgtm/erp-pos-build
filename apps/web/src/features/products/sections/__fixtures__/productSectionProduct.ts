import type {
  ProductSectionCostPrices,
  ProductSectionProduct,
  ProductSectionPublicProduct,
} from '../types'

export function makeProductSectionProduct(
  overrides: Partial<ProductSectionProduct> = {},
): ProductSectionProduct {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    name: 'Brake Pad',
    sku: 'BP-001',
    type: 'part',
    description: 'Low-dust front brake pad',
    sale_price: '50.000',
    purchase_price: '30.000',
    cost_price: '31.000',
    last_purchase_cost: '30.500',
    tax_rate: '19.00',
    default_tax_configuration_id: null,
    unit: 'pcs',
    unit_id: '00000000-0000-4000-8000-000000000002',
    units_per_pack: 1,
    shelf_location: 'A-01',
    reorder_point: '5.0000',
    reorder_quantity: '10.0000',
    quantity_decimals: 4,
    barcode: '6194000123456',
    is_active: true,
    is_active_for_ecommerce: true,
    is_physical: true,
    requires_batch_tracking: false,
    oem_numbers: [],
    cross_references: [],
    target_margin_override: null,
    minimum_margin_override: null,
    max_discount_percent: '10.00',
    platform_product_id: null,
    created_at: '2026-07-01T00:00:00Z',
    updated_at: '2026-07-02T00:00:00Z',
    has_variants: false,
    primary_image_url: null,
    media: [],
    brand: null,
    brand_source: null,
    category: {
      id: 7,
      company_id: '00000000-0000-4000-8000-000000000003',
      parent_id: null,
      name: 'Braking',
      slug: 'braking',
      description: null,
      image_url: null,
      path: 'braking',
      depth: 0,
      sort_order: 0,
      is_active: true,
      products_count: 1,
      breadcrumb: null,
      children: null,
      default_tax_rate: null,
      default_tax_configuration_id: null,
      max_discount_percent: null,
    },
    parapharmacy_metadata: null,
    automotive_metadata: null,
    opening: null,
    enrichment_status: null,
    latest_enrichment_result: null,
    stock_quantity: '12.0000',
    pricing_mode: 'auto',
    effective_margins: null,
    ...overrides,
  }
}

export function makeProductSectionViewData(): {
  product: ProductSectionPublicProduct
  costPrices: ProductSectionCostPrices
} {
  const fullProduct = makeProductSectionProduct()
  const {
    cost_price: costPrice,
    effective_margins: effectiveMargins,
    last_purchase_cost: lastPurchaseCost,
    purchase_price: purchasePrice,
    ...product
  } = fullProduct

  return {
    product,
    costPrices: { costPrice, effectiveMargins, lastPurchaseCost, purchasePrice },
  }
}

