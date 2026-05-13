import userEvent from '@testing-library/user-event'
import { QueryClient } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { renderWithProviders } from '@/test/renderWithProviders'

import { ReceiptSearchPage } from '../ReceiptSearchPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockPrintReceipt = vi.hoisted(() => vi.fn())
const mockGetOrCreateWebTerminal = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
  }
})

vi.mock('../../../hooks/useReceiptPrint', () => ({
  useReceiptPrint: () => ({ printReceipt: mockPrintReceipt }),
}))

vi.mock('../../../api/terminalApi', () => ({
  getOrCreateWebTerminal: mockGetOrCreateWebTerminal,
}))

vi.mock('@/hooks/useLocation', () => ({
  useLocation: () => ({ currentLocationId: null }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
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

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function receiptResponse(receiptNumber: string) {
  return {
    data: [
      {
        id: 'receipt-1',
        receipt_number: receiptNumber,
        receipt_type: 'sale',
        original_receipt_id: null,
        return_reason: null,
        terminal_id: 'terminal-1',
        terminal_code: 'TERM-1',
        cashier_name: 'Cashier',
        subtotal: '10.000',
        tax_amount: '0.000',
        total: '10.000',
        currency: 'TND',
        posted_at: '2026-05-11T10:00:00Z',
        is_voided: false,
        void_reason: null,
      },
    ],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 1,
    },
  }
}

const terminals = [{ id: 'terminal-1', code: 'TERM-1', name: 'Terminal 1' }]

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetOrCreateWebTerminal.mockResolvedValue({ id: 'terminal-1' })
  mockApiPost.mockResolvedValue({ data: { ok: true } })

  let receiptCalls = 0
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/pos/terminals') return terminals
    if (url.startsWith('/pos/receipts')) {
      receiptCalls += 1
      return receiptResponse(`R-${receiptCalls}`)
    }
    return {}
  })
})

afterEach(() => {
  resetTenant()
})

describe('ReceiptSearchPage tenant scope', () => {
  it('scopes receipt search reads and invalidates only the active tenant (.532-.535)', async () => {
    const user = userEvent.setup()
    const queryClient = createPersistentTestQueryClient()
    const tenantBKey = ['pos', 'receipts', { page: 1, per_page: 20 }, 'tenant-B', 'company-2']
    queryClient.setQueryData(tenantBKey, { data: [{ id: 'tenant-B-marker' }] })

    renderWithProviders(<ReceiptSearchPage />, { queryClient })

    await waitFor(() => {
      expect(queryClient.getQueryData(['pos', 'terminals', 'tenant-A', 'company-1'])).toEqual(terminals)
      expect(
        queryClient.getQueryData(['pos', 'receipts', { page: 1, per_page: 20 }, 'tenant-A', 'company-1']),
      ).toEqual(receiptResponse('R-1'))
    })

    await user.click(screen.getByTitle('pos:receiptSearch.void'))
    await user.type(screen.getByPlaceholderText('pos:receiptSearch.voidReasonPlaceholder'), 'mistake')
    const voidButtons = screen.getAllByRole('button', { name: 'pos:receiptSearch.void' })
    await user.click(voidButtons[voidButtons.length - 1])

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/pos/receipts/receipt-1/void', { reason: 'mistake' })
      expect(
        queryClient.getQueryData(['pos', 'receipts', { page: 1, per_page: 20 }, 'tenant-A', 'company-1']),
      ).toEqual(receiptResponse('R-2'))
    })

    expect(queryClient.getQueryData(tenantBKey)).toEqual({ data: [{ id: 'tenant-B-marker' }] })
  })

  it('does not fetch receipt search data without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<ReceiptSearchPage />)

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
