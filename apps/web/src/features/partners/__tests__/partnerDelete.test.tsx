import { Route, Routes, useLocation } from 'react-router-dom'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { renderWithProviders } from '@/test/renderWithProviders'

import { PartnerDetailPage } from '../PartnerDetailPage'
import { makePartnerDetail } from '../__fixtures__/partner'

/**
 * BUG-007 — there was no way to delete a partner from the UI. The backend
 * `DELETE /partners/{partner}` (permission `partners.delete`, admin-only) has
 * always existed; the frontend simply had no button, no mutation and no API
 * call. These tests lock the detail-page control, its permission gate, and the
 * translated 409 `PARTNER_HAS_DOCUMENTS` path added alongside it.
 */

const mockApiInstance = vi.hoisted(() => ({
  get: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: mockApiInstance }
})

const mockToast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('sonner', () => ({ toast: mockToast }))

vi.mock('../hooks/usePartnerBalanceRealtime', () => ({
  usePartnerBalanceRealtime: vi.fn(),
}))

const partner = makePartnerDetail({ id: 'partner-1', name: 'Acme Corp', type: 'customer' })

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="pathname">{location.pathname}</div>
}

function seedUser(roles: string[]): void {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-A',
      roles,
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [
      {
        id: 'company-1',
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function renderDetailPage() {
  return renderWithProviders(
    <>
      <LocationProbe />
      <Routes>
        <Route path="/sales/customers/:id" element={<PartnerDetailPage />} />
        <Route path="/sales/customers" element={<div>customers list</div>} />
      </Routes>
    </>,
    { route: '/sales/customers/partner-1' },
  )
}

describe('PartnerDetailPage delete (BUG-007)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedUser(['admin'])
    mockApiInstance.get.mockImplementation((url: string) => {
      if (url.includes('/account-balance')) {
        return Promise.resolve({
          data: {
            data: {
              partner_id: partner.id,
              currency: 'TND',
              unallocated_balance: '0.00',
              deposit_count: 0,
            },
          },
        })
      }
      if (url.includes('/deposits')) {
        return Promise.resolve({ data: { data: [] } })
      }
      if (/^\/partners\/[^/]+(\?|$)/.test(url)) {
        return Promise.resolve({ data: { data: partner } })
      }
      return Promise.resolve({
        data: {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
        },
      })
    })
    mockApiInstance.delete.mockResolvedValue({ status: 204, data: '' })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  it('deletes the partner after confirmation and returns to the matching list', async () => {
    const user = userEvent.setup()
    renderDetailPage()

    await waitFor(() => {
      expect(screen.getByText('Acme Corp')).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /delete/i }))

    // Confirmation is required — nothing is sent before confirming.
    expect(mockApiInstance.delete).not.toHaveBeenCalled()

    await user.click(screen.getByTestId('confirm-dialog-confirm'))

    await waitFor(() => {
      expect(mockApiInstance.delete).toHaveBeenCalledWith('/partners/partner-1')
    })
    await waitFor(() => {
      expect(screen.getByTestId('pathname')).toHaveTextContent('/sales/customers')
    })
    expect(mockToast.success).toHaveBeenCalled()
  })

  it('hides the delete control from users without partners.delete', async () => {
    seedUser(['viewer'])
    renderDetailPage()

    await waitFor(() => {
      expect(screen.getByText('Acme Corp')).toBeInTheDocument()
    })

    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()
  })

  it('surfaces a translated message and stays on the page when the backend returns 409 PARTNER_HAS_DOCUMENTS', async () => {
    const user = userEvent.setup()
    mockApiInstance.delete.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 409,
        data: {
          error: {
            code: 'PARTNER_HAS_DOCUMENTS',
            message: 'Cannot delete this partner: it is still referenced by financial records.',
            details: { documents: 2 },
          },
        },
      },
    })

    renderDetailPage()

    await waitFor(() => {
      expect(screen.getByText('Acme Corp')).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /delete/i }))
    await user.click(screen.getByTestId('confirm-dialog-confirm'))

    await waitFor(() => {
      expect(mockToast.error).toHaveBeenCalledWith(expect.stringContaining('Acme Corp'))
    })
    // Not the raw server string, and definitely not a navigation away.
    expect(mockToast.error).toHaveBeenCalledWith(expect.stringMatching(/cannot be deleted/i))
    expect(screen.getByTestId('pathname')).toHaveTextContent('/sales/customers/partner-1')
  })
})
