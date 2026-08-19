/**
 * OQ-1 / Wave 0 T14 — the app name is a config value, not a hardcoded brand string.
 *
 * OWNER-DECISIONS:9 — "ERP product names are NOT final… the app name in
 * privacy/support copy must become a placeholder/config value, not a hardcoded
 * string. Do not bake any brand string into copy."
 *
 * The privacy copy interpolates `{{appName}}`; the page supplies it from
 * `useProductConfig().productName`, whose single source is the `PRODUCT_INFO`
 * map in `src/contexts/ProductConfigContext.tsx:31-40`. No new config mechanism
 * and no new env var is introduced.
 *
 * The product is seeded to a NON-DEFAULT value (`otospex`; the default is
 * `izipos`) so the assertion cannot pass by coincidence.
 */

import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { PrivacyPolicyPage } from '../PrivacyPolicyPage'
import { renderWithProviders } from '@/test/renderWithProviders'

describe('PrivacyPolicyPage — app name comes from product config (OQ-1)', () => {
  it('renders the seeded product name in the section 1 body', () => {
    renderWithProviders(<PrivacyPolicyPage />, {
      productConfig: { product: 'otospex' },
    })

    expect(screen.getByText(/Otospex \("we", "our", "us"\) is committed/)).toBeInTheDocument()
  })

  it('renders the seeded product name in the section 3 purpose list', () => {
    renderWithProviders(<PrivacyPolicyPage />, {
      productConfig: { product: 'otospex' },
    })

    expect(screen.getByText(/Provide, maintain, and improve the Otospex platform/)).toBeInTheDocument()
  })

  it('leaves no AutoERP literal anywhere in the rendered page', () => {
    const { container } = renderWithProviders(<PrivacyPolicyPage />, {
      productConfig: { product: 'otospex' },
    })

    expect(container.textContent).not.toMatch(/AutoERP/)
  })

  it('follows the configured product rather than a baked-in name', () => {
    const { container } = renderWithProviders(<PrivacyPolicyPage />, {
      productConfig: { product: 'izipos' },
    })

    expect(container.textContent).toMatch(/IziPOS/)
    expect(container.textContent).not.toMatch(/Otospex/)
    expect(container.textContent).not.toMatch(/AutoERP/)
  })
})
