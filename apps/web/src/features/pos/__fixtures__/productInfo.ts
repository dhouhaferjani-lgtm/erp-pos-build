/**
 * Fixture factories for ProductInfoModal tests.
 *
 * Types are re-exports from the component file so the factories fail-compile
 * when the wire shape changes.
 */

import type {
  ProductDetailResponse,
  StockLevel,
} from '../organisms/ProductInfoModal/ProductInfoModal'

export interface StockLevelsResponse {
  locations: StockLevel[]
  totals: {
    quantity: string
    reserved: string
    available: string
    incoming: string
    projected_available: string
  }
}

export function makeProductDetail(
  overrides: Partial<ProductDetailResponse> = {},
): ProductDetailResponse {
  return {
    id: '1',
    name: 'Test Product',
    sku: 'TEST-001',
    description: 'Test product description',
    sale_price: '29.99',
    tax_rate: '19',
    category: {
      id: 'cat-1',
      name: 'Test Category',
    },
    image_url: 'https://example.com/image.jpg',
    ...overrides,
  }
}

export function makeProductDetailWithParapharmacy(
  overrides: Partial<ProductDetailResponse> = {},
): ProductDetailResponse {
  return {
    ...makeProductDetail(),
    parapharmacy_metadata: {
      ingredients: [
        {
          id: 'ing-1',
          name: { en: 'Vitamin C', fr: 'Vitamine C' },
          concentration: '500mg',
        },
        {
          id: 'ing-2',
          name: { en: 'Zinc', fr: 'Zinc' },
          concentration: '15mg',
        },
      ],
      key_components: [
        {
          id: 'kc-1',
          name: { en: 'Antioxidant Blend', fr: 'Mélange antioxydant' },
          benefit: {
            en: 'Supports immune system',
            fr: 'Soutient le système immunitaire',
          },
        },
      ],
      health_claims: [
        {
          id: 'hc-1',
          claim: {
            en: 'Contributes to normal immune function',
            fr: 'Contribue à la fonction immunitaire normale',
          },
          regulation_reference: 'EU Reg 432/2012',
        },
      ],
      certifications: [
        {
          id: 'cert-1',
          name: { en: 'Organic Certified', fr: 'Certifié biologique' },
          logo_url: 'https://example.com/cert-logo.jpg',
          issuing_body: 'EU Organic',
        },
      ],
    },
    ...overrides,
  }
}

export function makeStockLevel(overrides: Partial<StockLevel> = {}): StockLevel {
  return {
    id: 'stock-1',
    location_id: 'loc-1',
    location_name: 'Main Warehouse',
    quantity_decimals: 4,
    quantity: '60',
    available: '50',
    reserved: '10',
    incoming: '0',
    projected_available: '50',
    min_quantity: null,
    max_quantity: null,
    is_below_minimum: false,
    ...overrides,
  }
}

export function makeStockLevelsResponse(
  locations: StockLevel[],
): StockLevelsResponse {
  // Sum simple numeric fields for totals. Tests only assert locations list, so
  // totals can be roughly aggregated.
  const totals = locations.reduce(
    (acc, loc) => ({
      quantity: String(parseFloat(acc.quantity) + parseFloat(loc.quantity || '0')),
      reserved: String(parseFloat(acc.reserved) + parseFloat(loc.reserved || '0')),
      available: String(parseFloat(acc.available) + parseFloat(loc.available || '0')),
      incoming: String(parseFloat(acc.incoming) + parseFloat(loc.incoming || '0')),
      projected_available: String(
        parseFloat(acc.projected_available) +
          parseFloat(loc.projected_available || '0'),
      ),
    }),
    {
      quantity: '0',
      reserved: '0',
      available: '0',
      incoming: '0',
      projected_available: '0',
    },
  )
  return { locations, totals }
}
