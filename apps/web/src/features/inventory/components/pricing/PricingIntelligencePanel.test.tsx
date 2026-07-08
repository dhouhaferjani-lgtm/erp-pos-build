import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { PricingIntelligencePanel } from './PricingIntelligencePanel'

const mockHasPermission = vi.hoisted(() => vi.fn())

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: Record<string, string>) => {
      if (key === 'inventory:pricing.lastCost' && options?.['amount']) return `Last: ${options['amount']}`
      return key
    },
  }),
}))

const product = {
  id: 'product-1',
  name: 'Brake Pad',
  sku: 'BP-001',
  sale_price: '100.000',
  cost_price: '80.000',
  last_purchase_cost: '77.000',
  tax_rate: '19.00',
  max_discount_percent: '12.50',
  effective_margins: {
    target_margin: '30.00',
    minimum_margin: '15.00',
    source: 'product',
  },
}

describe('PricingIntelligencePanel', () => {
  beforeEach(() => {
    mockHasPermission.mockReset()
  })

  it('hides cost, last purchase, margin, and floor data without pricing.view_cost_prices', () => {
    mockHasPermission.mockReturnValue(false)

    render(
      <PricingIntelligencePanel
        product={product}
        currency="EUR"
        locale="en-US"
      />,
    )

    expect(screen.getByText('pricing.salePriceHt')).toBeInTheDocument()
    expect(screen.queryByText('pricing.wac')).not.toBeInTheDocument()
    expect(screen.queryByText('pricing.lastPurchasePrice')).not.toBeInTheDocument()
    expect(screen.queryByText('pricing.margin')).not.toBeInTheDocument()
    expect(screen.queryByText('pricing.floorPrice')).not.toBeInTheDocument()
  })

  it('shows guarded pricing intelligence and backend verdict fields to cost-price viewers', () => {
    mockHasPermission.mockReturnValue(true)

    render(
      <PricingIntelligencePanel
        product={product}
        currency="EUR"
        locale="en-US"
        verdict={{
          allowed: false,
          blocksSale: false,
          severity: 'warn',
          requiresPermission: 'pricing.sell_below_minimum_margin',
          maxDiscountPercent: '12.50',
          discountPercent: '18.00',
          floorPriceNet: '92.000',
          floorBasis: 'minimum_margin',
          floorEnforcement: 'warn_requires_permission',
          mode: 'Advisory',
          overridable: true,
          requiresReason: true,
          policyVersion: '2026-07-08',
          policyAsOf: '2026-07-08T00:00:00Z',
          reasons: ['below_minimum_margin'],
          meta: {},
        }}
      />,
    )

    expect(screen.getByText('pricing.wac')).toBeInTheDocument()
    expect(screen.getByText('pricing.lastPurchasePrice')).toBeInTheDocument()
    expect(screen.getByText('pricing.margin')).toBeInTheDocument()
    expect(screen.getByText((content) => content.includes('pricing.floorPrice'))).toBeInTheDocument()
    expect(screen.getByText('pricing.sell_below_minimum_margin')).toBeInTheDocument()
  })
})
