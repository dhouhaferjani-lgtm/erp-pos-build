import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { RecordPaymentModal } from '../RecordPaymentModal'

const UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'TND',
    decimals: 2,
    format: (amount: string | number) => String(amount),
    symbol: 'TND',
  }),
}))

vi.mock('../../AddRepositoryModal', () => ({
  AddRepositoryModal: ({ onSuccess }: { onSuccess: () => void | Promise<void> }) => (
    <button type="button" onClick={() => { void onSuccess(); }}>repository-success</button>
  ),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

// Stable identity: the modal's reset effect depends on [isOpen, prefill], so a
// freshly-built prefill object on every rerender would fire the effect for the
// wrong reason and mask the open/close behaviour under test.
const PREFILL = {
  partner_id: 'partner-1',
  partner_name: 'Partner A',
  amount: 100,
  reference: 'INV-1',
  document_id: 'doc-1',
  document_type: 'invoice' as const,
}

function mockLookupResponses() {
  mockApiGet.mockImplementation((url: string) => {
    if (url === '/payment-methods') {
      return Promise.resolve({ data: { data: [{ id: 'method-1', code: 'CASH', name: 'Cash', is_physical: false, has_maturity: false }] } })
    }
    if (url === '/payment-repositories') {
      return Promise.resolve({ data: { data: [{ id: 'repo-1', code: 'CASH', name: 'Cash Register', type: 'cash_register' }] } })
    }
    return Promise.resolve({ data: { data: [] } })
  })
}

/** Fill the single payment line, confirm it, then press Record. */
async function confirmLineAndRecord(amount: string) {
  await screen.findByRole('option', { name: 'Cash' })
  await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method *'), 'method-1')
  await userEvent.type(screen.getByLabelText('treasury:payments.amount *'), amount)
  await userEvent.selectOptions(screen.getByLabelText('treasury:repositories.title'), 'repo-1')
  await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
  await act(async () => {
    await userEvent.click(screen.getByRole('button', { name: /treasury:payments.record/ }))
  })
}

/** Read the idempotency_key off a recorded POST body without an unsafe cast. */
function postedIdempotencyKey(callIndex: number): string {
  const body: unknown = mockApiPost.mock.calls[callIndex]?.[1]
  if (typeof body !== 'object' || body === null || !('idempotency_key' in body)) {
    throw new Error(`POST #${String(callIndex)} carried no request body`)
  }
  const key: unknown = body.idempotency_key
  if (typeof key !== 'string') {
    throw new Error(`POST #${String(callIndex)} carried no idempotency_key`)
  }
  return key
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockLookupResponses()
})

afterEach(() => {
  resetTenant()
})

describe('RecordPaymentModal idempotency key lifetime', () => {
  it('mints a DIFFERENT idempotency_key for two separate modal opens (each open is a new payment intent)', async () => {
    // The first attempt must FAIL: on success the key already rotates in
    // onSuccess, which would mask a modal that never rotates on re-open. The
    // money-visible scenario is a LOST RESPONSE — the server committed, the
    // client saw an error — after which the operator reopens and enters a
    // different amount. Reusing the key would replay the first payment as 200.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({
      payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '1000.00' }],
      document: { id: 'doc-1', document_number: 'INV-1', balance_due: '0.00', status: 'paid' },
      excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
    })

    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={PREFILL} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLineAndRecord('400')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // The hosts (InvoiceDetailPage / SalesOrderDetailPage / PurchaseOrderDetailPage)
    // render the modal gated on partner_id, so closing only flips isOpen — the
    // component stays MOUNTED across the close/reopen cycle.
    rerender(<RecordPaymentModal isOpen={false} onClose={onClose} prefill={PREFILL} />)
    rerender(<RecordPaymentModal isOpen onClose={onClose} prefill={PREFILL} />)

    await confirmLineAndRecord('1000')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    const firstKey = postedIdempotencyKey(0)
    const secondKey = postedIdempotencyKey(1)
    expect(firstKey).toMatch(UUID_REGEX)
    expect(secondKey).toMatch(UUID_REGEX)
    expect(secondKey).not.toBe(firstKey)
  })

  it('keeps the SAME idempotency_key when retrying after a failed submission from the same open', async () => {
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({
      payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '400.00' }],
      document: { id: 'doc-1', document_number: 'INV-1', balance_due: '600.00', status: 'partially_paid' },
      excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
    })

    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={PREFILL} />, {
      wrapper: wrapper(createClient()),
    })

    await confirmLineAndRecord('400')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // Retry from the SAME open — the key must survive the error so the server
    // can deduplicate a request that may already have committed.
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: /treasury:payments.record/ }))
    })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    expect(postedIdempotencyKey(1)).toBe(postedIdempotencyKey(0))
  })

  it('mints a DIFFERENT idempotency_key once the payload is edited after a failed submit', async () => {
    // Ruling: one key = one submit intent. An UNCHANGED retry replays (test
    // above). An EDITED payload is a NEW intent: reusing the key would make the
    // server return the FIRST (possibly committed) batch as HTTP 200 and the
    // success panel would show figures the operator never submitted.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({
      payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '400.00' }],
      document: { id: 'doc-1', document_number: 'INV-1', balance_due: '600.00', status: 'partially_paid' },
      excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
    })

    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={PREFILL} />, {
      wrapper: wrapper(createClient()),
    })

    await confirmLineAndRecord('400')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // payment_date rides in the POST body, and unlike a confirmed line's amount
    // it stays editable after the line is locked — so it is the payload edit an
    // operator can actually make on this surface after a failure.
    await act(async () => {
      fireEvent.change(screen.getByLabelText('treasury:payments.date *'), {
        target: { value: '2026-09-01' },
      })
      await Promise.resolve()
    })

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: /treasury:payments.record/ }))
    })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    const firstKey = postedIdempotencyKey(0)
    const secondKey = postedIdempotencyKey(1)
    expect(firstKey).toMatch(UUID_REGEX)
    expect(secondKey).toMatch(UUID_REGEX)
    expect(secondKey).not.toBe(firstKey)
  })
})
