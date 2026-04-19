import { describe, it, expect } from 'vitest'
import { useProductConfig } from '@/contexts/ProductConfigContext'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { renderWithProviders } from '../renderWithProviders'

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
      companyConfig: {
        vertical: 'pharmacy',
        default_modules: [],
        enabled_extras: [],
        all_enabled_modules: [],
        currency: 'EUR',
        locale: 'en',
        country_code: null,
        smart_prompts_enabled: false,
        smart_prompts_variant: 'off',
      },
    })
    expect(getByTestId('product').textContent).toBe('otospex')
    expect(getByTestId('vertical').textContent).toBe('pharmacy')
  })
})
