import { describe, it, expect } from 'vitest'
import { useProductConfig } from '@/contexts/ProductConfigContext'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { renderWithProviders } from '../renderWithProviders'
import { pharmacyCompanyConfig } from '../fixtures/companyConfig'

function Probe() {
  const product = useProductConfig()
  const company = useCompanyConfig()
  return (
    <div>
      <span data-testid="product">{product.product}</span>
      <span data-testid="vertical">{company.config?.vertical ?? ''}</span>
    </div>
  )
}

describe('renderWithProviders', () => {
  it('provides default product and company config', () => {
    const { getByTestId } = renderWithProviders(<Probe />)
    expect(getByTestId('product').textContent).toBe('izipos')
    expect(getByTestId('vertical').textContent).toBe('generic')
  })

  it('accepts overrides', () => {
    const { getByTestId } = renderWithProviders(<Probe />, {
      productConfig: { product: 'otospex' },
      companyConfig: pharmacyCompanyConfig,
    })
    expect(getByTestId('product').textContent).toBe('otospex')
    expect(getByTestId('vertical').textContent).toBe(pharmacyCompanyConfig.vertical)
  })
})
