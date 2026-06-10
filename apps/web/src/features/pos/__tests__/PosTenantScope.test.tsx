import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CashOperationModal } from '../components/CashOperationModal'
import { EarnPointsPreview } from '../components/EarnPointsPreview'
import { LoyaltyRewardSelector } from '../components/LoyaltyRewardSelector'
import { TerminalSelector } from '../components/TerminalSelector'
import { useActiveMenu } from '../hooks/useActiveMenu'
import { AdvancedPaymentsModal } from '../organisms/AdvancedPaymentsModal/AdvancedPaymentsModal'
import { ProductInfoModal } from '../organisms/ProductInfoModal/ProductInfoModal'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiGetHelper = vi.hoisted(() => vi.fn())
const mockRecordCashDeposit = vi.hoisted(() => vi.fn())
const mockPreviewEarning = vi.hoisted(() => vi.fn())
const mockGetRewards = vi.hoisted(() => vi.fn())
const mockFetchPaymentMethods = vi.hoisted(() => vi.fn())
const mockFetchPaymentRepositories = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
    },
    apiGet: mockApiGetHelper,
  }
})

vi.mock('../api/shiftApi', () => ({
  recordCashDeposit: mockRecordCashDeposit,
  recordCashPayout: vi.fn(),
}))

vi.mock('../api/loyaltyApi', () => ({
  previewEarning: mockPreviewEarning,
  getRewards: mockGetRewards,
  redeemReward: vi.fn(),
}))

vi.mock('../api/paymentMethodApi', () => ({
  fetchPaymentMethods: mockFetchPaymentMethods,
}))

vi.mock('../api/paymentRepositoryApi', () => ({
  fetchPaymentRepositories: mockFetchPaymentRepositories,
}))

vi.mock('../hooks', () => ({
  useCompanySettings: () => ({ autoPrintReceipts: false }),
}))

vi.mock('../hooks/useDiscountPermissions', () => ({
  useDiscountPermissions: () => ({ permissions: { canDiscount: true } }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    toFixed: (value: number) => value.toFixed(2),
  }),
}))

vi.mock('@/hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => 'VAT',
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: unknown) => typeof fallback === 'string' ? fallback : key,
    i18n: { language: 'en' },
  }),
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

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockRecordCashDeposit.mockResolvedValue({})
  mockPreviewEarning.mockResolvedValue({ points_to_earn: 10 })
  mockGetRewards.mockResolvedValue({ current_balance: '100', rewards: [] })
  mockFetchPaymentMethods.mockResolvedValue([])
  mockFetchPaymentRepositories.mockResolvedValue([])
  mockApiGetHelper.mockImplementation((url: string) => {
    if (url === '/pos/terminals') return Promise.resolve([])
    if (url === '/active-menu') return Promise.resolve({ id: 'menu-1', name: 'Main', categories: [] })
    if (url === '/products/product-1') return Promise.resolve({ id: 'product-1', name: 'Product A', sale_price: '10' })
    if (url === '/products/product-1/stock-levels') return Promise.resolve({ locations: [] })
    return Promise.resolve({})
  })
})

afterEach(() => {
  resetTenant()
})

describe('POS tenant scope', () => {
  it('scopes POS reads and invalidations (.609-.619)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['pos', 'shift-balance', 'shift-1', 'tenant-A', 'company-1'], { balance: '100' })
    queryClient.setQueryData(['pos', 'shift', 'TERM-1', 'tenant-A', 'company-1'], { id: 'shift-1' })
    queryClient.setQueryData(['pos', 'shift-balance', 'shift-1', 'tenant-B', 'company-2'], { balance: '200' })

    render(
      <>
        <CashOperationModal isOpen={true} onClose={vi.fn()} type="deposit" shiftId="shift-1" terminalCode="TERM-1" />
        <EarnPointsPreview enrollmentId="enroll-1" cartTotal="10" cartItems={[]} />
        <LoyaltyRewardSelector enrollmentId="enroll-1" onRewardRedeemed={vi.fn()} />
        <TerminalSelector onSelect={vi.fn()} />
        <AdvancedPaymentsModal
          isOpen={true}
          onClose={vi.fn()}
          cartItems={[]}
          onComplete={vi.fn()}
          terminalCode="TERM-1"
        />
        <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="product-1" />
      </>,
      { wrapper: wrapper(queryClient) },
    )
    renderHook(() => useActiveMenu(), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['loyalty', 'preview-earning', 'enroll-1', '10', 0, 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['loyalty', 'rewards', 'enroll-1', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['pos', 'terminals', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['pos', 'active-menu', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-methods', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-repositories', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['product', 'product-1', 'tenant-A', 'company-1'])).toBeDefined()
    })

    await user.type(screen.getByPlaceholderText('0.000'), '25')
    await user.type(screen.getByPlaceholderText('common:pos.reasonPlaceholder'), 'cash drop')
    await user.click(screen.getByRole('button', { name: 'common:pos.recordDeposit' }))

    await waitFor(() => {
      expect(queryClient.getQueryState(['pos', 'shift-balance', 'shift-1', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
      expect(queryClient.getQueryState(['pos', 'shift', 'TERM-1', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['pos', 'shift-balance', 'shift-1', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('does not fetch POS reads without tenant/company state', () => {
    const queryClient = createClient()
    resetTenant()

    render(
      <>
        <EarnPointsPreview enrollmentId="enroll-1" cartTotal="10" cartItems={[]} />
        <TerminalSelector onSelect={vi.fn()} />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    expect(mockPreviewEarning).not.toHaveBeenCalled()
    expect(mockApiGetHelper).not.toHaveBeenCalled()
  })
})
