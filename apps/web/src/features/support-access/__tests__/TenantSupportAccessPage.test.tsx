import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ProductConfigProvider, type Product } from '@/contexts/ProductConfigContext'
import { useAuthStore } from '@/stores/authStore'
import { supportAccessFixture } from '../__fixtures__/supportAccess'
import { TenantSupportAccessPage } from '../pages/TenantSupportAccessPage'

const createWindow = vi.fn()
const approveGrant = vi.fn()
const rejectGrant = vi.fn()
const revokeGrant = vi.fn()

vi.mock('../hooks/useTenantSupportAccess', () => ({
  useTenantSupportAccess: () => ({
    overview: supportAccessFixture,
    isLoading: false,
    error: null,
    createWindow,
    approveGrant,
    rejectGrant,
    revokeGrant,
    isMutating: false,
  }),
}))

describe('TenantSupportAccessPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows tenant controls and sanitized history to a viewer', () => {
    setUser(['support-access.view'])
    renderPage()

    expect(screen.getByRole('heading', { name: /support access/i })).toBeInTheDocument()
    expect(screen.getAllByText('SUP-9000').length).toBeGreaterThan(0)
    expect(screen.getByText(/products.index/i)).toBeInTheDocument()
    expect(screen.queryByText(/operator@example/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument()
  })

  it('allows managers to pre-grant, approve, reject, and revoke', async () => {
    setUser(['support-access.view', 'support-access.manage'])
    const user = userEvent.setup()
    renderPage()

    expect(screen.getByRole('button', { name: /approve/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /reject/i })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /revoke/i }).length).toBeGreaterThan(0)

    await user.type(screen.getByLabelText(/support reason/i), 'Quarterly support window')
    await user.type(screen.getByLabelText(/ticket reference/i), 'SUP-9010')
    await user.click(screen.getByRole('button', { name: /create support window/i }))
    expect(createWindow).toHaveBeenCalled()
  })

  // ── OQ-1 / Wave 0 T14 — the subtitle's app name is a config value ──────────
  // OWNER-DECISIONS:9. The subtitle is the SECOND component-level proof of the
  // placeholder change (the first is PrivacyPolicyPage); a locale-content
  // assertion cannot substitute for it, because only a render proves the page
  // actually passes `{ appName: productName }`.

  it('renders the seeded product name in the subtitle instead of a brand literal', () => {
    setUser(['support-access.view'])
    // Seeded to the NON-default product so the assertion cannot pass by coincidence.
    renderPage('otospex')

    expect(screen.getByText(/Otospex support may enter your workspace/i)).toBeInTheDocument()
  })

  it('leaves no AutoERP literal in the rendered page', () => {
    setUser(['support-access.view'])
    const { container } = renderPage('otospex')

    expect(container.textContent).not.toMatch(/AutoERP/)
  })

  it('follows the configured product rather than a baked-in name', () => {
    setUser(['support-access.view'])
    const { container } = renderPage('izipos')

    expect(container.textContent).toMatch(/IziPOS support may enter your workspace/i)
    expect(container.textContent).not.toMatch(/Otospex/)
  })
})

function setUser(permissions: string[]) {
  useAuthStore.getState().setAuth({
    id: 'tenant-admin',
    name: 'Tenant Admin',
    email: 'admin@tenant.test',
    tenant_id: 'tenant-1',
    roles: ['admin'],
    permissions,
    email_verified_at: null,
    impersonation: null,
  })
}

// Wave 0 T14: the page now calls `useProductConfig()` to interpolate the app
// name into its subtitle, and that hook THROWS when no provider is mounted
// (ProductConfigContext.tsx:128-135). The bare QueryClientProvider +
// MemoryRouter wrapper this suite used would therefore have thrown for every
// case. Wrapping explicitly in `ProductConfigProvider` (rather than switching to
// `renderWithProviders`) is the minimal repair: it adds exactly the missing
// provider and does not also pull in `CompanyConfigProvider` and a seeded
// company-config cache entry, which none of these tests asked for. The default
// stays `izipos` so the pre-existing cases keep their previous behaviour.
function renderPage(product: Product = 'izipos') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <ProductConfigProvider initialProduct={product}>
          <TenantSupportAccessPage />
        </ProductConfigProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
