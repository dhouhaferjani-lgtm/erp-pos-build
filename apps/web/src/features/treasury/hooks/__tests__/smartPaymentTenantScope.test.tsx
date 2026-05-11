import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { ApplyAllocationResponse, ToleranceSettings } from '@/types/treasury'

import { useApplyAllocation, useToleranceSettings } from '../useSmartPayment'

const mockGetToleranceSettings = vi.hoisted(() => vi.fn())
const mockApplyPaymentAllocation = vi.hoisted(() => vi.fn())
const mockPreviewPaymentAllocation = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('../../api/smartPayment', () => ({
  applyPaymentAllocation: mockApplyPaymentAllocation,
  getToleranceSettings: mockGetToleranceSettings,
  previewPaymentAllocation: mockPreviewPaymentAllocation,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
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
    companies: [],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function toleranceFixture(): ToleranceSettings {
  return {
    enabled: true,
    max_amount: '5.0000',
    percentage: '0.0050',
    source: 'company',
  }
}

function allocationResponseFixture(): ApplyAllocationResponse {
  return {
    payment_id: 'payment-1',
    allocations: [
      {
        document_id: 'invoice-1',
        document_number: 'INV-1',
        amount: '100.000',
      },
      {
        document_id: 'invoice-2',
        document_number: 'INV-2',
        amount: '50.000',
      },
    ],
    total_allocated: '150.000',
    excess_amount: '0.000',
    message: 'Allocated',
  }
}

function useProbe(queryKey: readonly unknown[], queryFn: () => Promise<unknown>) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(queryKey),
    queryFn,
    enabled: tenantId !== null && companyId !== null,
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetToleranceSettings.mockResolvedValue(toleranceFixture())
  mockApplyPaymentAllocation.mockResolvedValue(allocationResponseFixture())
  mockPreviewPaymentAllocation.mockResolvedValue({})
})

afterEach(() => {
  resetTenant()
})

describe('smart payment hooks tenant scope', () => {
  it('wraps tolerance settings with the active tenant and company (.735)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => useToleranceSettings(), { wrapper })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData([
      'smart-payment',
      'tolerance-settings',
      'tenant-A',
      'company-1',
    ])).toEqual(toleranceFixture())
  })

  it('does not fetch tolerance settings without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => useToleranceSettings(), { wrapper })

    expect(mockGetToleranceSettings).not.toHaveBeenCalled()
  })

  it('bounds allocation invalidation to the active tenant cache (.736-.739)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let paymentsCalls = 0
    let paymentCalls = 0
    let invoiceOneCalls = 0
    let invoiceTwoCalls = 0
    let partnerBalanceCalls = 0

    const paymentsQuery = vi.fn(async () => {
      paymentsCalls += 1
      return [{ id: `payment-list-${paymentsCalls}` }]
    })
    const paymentQuery = vi.fn(async () => {
      paymentCalls += 1
      return { id: `payment-${paymentCalls}` }
    })
    const invoiceOneQuery = vi.fn(async () => {
      invoiceOneCalls += 1
      return { id: `invoice-one-${invoiceOneCalls}` }
    })
    const invoiceTwoQuery = vi.fn(async () => {
      invoiceTwoCalls += 1
      return { id: `invoice-two-${invoiceTwoCalls}` }
    })
    const partnerBalanceQuery = vi.fn(async () => {
      partnerBalanceCalls += 1
      return { id: `partner-balance-${partnerBalanceCalls}` }
    })

    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })
    queryClient.setQueryData(['payment', 'payment-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-payment' })
    queryClient.setQueryData(['invoice', 'invoice-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoice-1' })
    queryClient.setQueryData(['invoice', 'invoice-2', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoice-2' })
    queryClient.setQueryData(['partner-balance', 'tenant-B', 'company-1'], { marker: 'tenant-B-partner-balance' })

    const { result: reads } = renderHook(() => ({
      invoiceOne: useProbe(['invoice', 'invoice-1'], invoiceOneQuery),
      invoiceTwo: useProbe(['invoice', 'invoice-2'], invoiceTwoQuery),
      partnerBalance: useProbe(['partner-balance'], partnerBalanceQuery),
      payment: useProbe(['payment', 'payment-1'], paymentQuery),
      payments: useProbe(['payments'], paymentsQuery),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.invoiceOne.isSuccess).toBe(true)
      expect(reads.current.invoiceTwo.isSuccess).toBe(true)
      expect(reads.current.partnerBalance.isSuccess).toBe(true)
      expect(reads.current.payment.isSuccess).toBe(true)
      expect(reads.current.payments.isSuccess).toBe(true)
      expect(paymentsCalls).toBe(1)
      expect(paymentCalls).toBe(1)
      expect(invoiceOneCalls).toBe(1)
      expect(invoiceTwoCalls).toBe(1)
      expect(partnerBalanceCalls).toBe(1)
    })

    const { result: mutation } = renderHook(() => useApplyAllocation(), { wrapper })

    await act(async () => {
      await mutation.current.mutateAsync({
        payment_id: 'payment-1',
        allocation_method: 'fifo',
      })
    })

    await waitFor(() => {
      expect(paymentsCalls).toBe(2)
      expect(paymentCalls).toBe(2)
      expect(invoiceOneCalls).toBe(2)
      expect(invoiceTwoCalls).toBe(2)
      expect(partnerBalanceCalls).toBe(2)
    })

    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
    expect(queryClient.getQueryData(['payment', 'payment-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payment' })
    expect(queryClient.getQueryData(['invoice', 'invoice-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoice-1' })
    expect(queryClient.getQueryData(['invoice', 'invoice-2', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoice-2' })
    expect(queryClient.getQueryData(['partner-balance', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-partner-balance' })
  })
})
