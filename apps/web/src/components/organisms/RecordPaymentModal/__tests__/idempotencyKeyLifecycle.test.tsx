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

/**
 * A FRESH object on every call — exactly what the three production hosts pass:
 * `InvoiceDetailPage.tsx:899`, `SalesOrderDetailPage.tsx:784` and
 * `PurchaseOrderDetailPage.tsx:713` all build `prefill` as an inline object
 * literal, so its identity changes on every parent re-render. A hoisted
 * constant would stabilise the fixture PAST the production condition and hide
 * a rotation that fires on re-render rather than on the open transition.
 */
function makePrefill() {
  return {
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    amount: 100,
    reference: 'INV-1',
    document_id: 'doc-1',
    document_type: 'invoice' as const,
  }
}

function deterministicUuid(n: number): `${string}-${string}-${string}-${string}-${string}` {
  return `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`
}

/**
 * Records every `crypto.randomUUID()` mint.
 *
 * An idempotency key rotation is only observable through a POST, and this
 * surface wipes its form whenever `prefill` changes identity — so a re-render
 * that rotated the key could never be told apart from one that did not by
 * comparing two posted keys (the operator has to re-enter the line either way,
 * and that re-entry legitimately rotates). Counting mints across a precise
 * window is the observation channel that survives the wipe.
 */
function installUuidRecorder(): { minted: string[]; restore: () => void } {
  const minted: string[] = []
  const spy = vi.spyOn(globalThis.crypto, 'randomUUID').mockImplementation(() => {
    const value = deterministicUuid(minted.length + 1)
    minted.push(value)
    return value
  })
  return { minted, restore: () => { spy.mockRestore() } }
}

function lookupPayload(url: string): { data: { data: unknown[] } } {
  if (url === '/payment-methods') {
    return { data: { data: [{ id: 'method-1', code: 'CASH', name: 'Cash', is_physical: false, has_maturity: false }] } }
  }
  if (url === '/payment-repositories') {
    return { data: { data: [{ id: 'repo-1', code: 'CASH', name: 'Cash Register', type: 'cash_register' }] } }
  }
  return { data: { data: [] } }
}

function mockLookupResponses() {
  mockApiGet.mockImplementation((url: string) => Promise.resolve(lookupPayload(url)))
}

/**
 * Same payloads, but resolved on a MACROTASK — i.e. with the latency a real
 * HTTP refetch has. `onSuccess` awaits `Promise.all([...invalidateQueries])`,
 * and the modal's own `open-invoices` query (`RecordPaymentModal.tsx:259-266`)
 * matches one of those predicates, so the awaited window is exactly as long as
 * this delay. The default microtask mocks close that window before React can
 * flush a render, which is what hides the `isError` guard from every other test
 * in this file.
 */
function mockLookupResponsesWithLatency(delayMs: number) {
  mockApiGet.mockImplementation((url: string) => new Promise<{ data: { data: unknown[] } }>((resolve) => {
    setTimeout(() => { resolve(lookupPayload(url)) }, delayMs)
  }))
}

/** Let real timers and React's passive effects run for `ms`. */
async function settleFor(ms: number) {
  await act(async () => {
    await new Promise<void>((resolve) => { setTimeout(resolve, ms) })
  })
}

/** Fill the single payment line and confirm it. No submit. */
async function confirmLine(amount: string) {
  await screen.findByRole('option', { name: 'Cash' })
  await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method *'), 'method-1')
  await userEvent.type(screen.getByLabelText('treasury:payments.amount *'), amount)
  await userEvent.selectOptions(screen.getByLabelText('treasury:repositories.title'), 'repo-1')
  await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
}

/** Press Record. */
async function pressRecord() {
  await act(async () => {
    await userEvent.click(screen.getByRole('button', { name: /treasury:payments.record/ }))
  })
}

/** Fill the single payment line, confirm it, then press Record. */
async function confirmLineAndRecord(amount: string) {
  await confirmLine(amount)
  await pressRecord()
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

/** Read any field off a recorded POST body without an unsafe cast. */
function postedField(callIndex: number, field: string): unknown {
  const body: unknown = mockApiPost.mock.calls[callIndex]?.[1]
  if (typeof body !== 'object' || body === null) {
    throw new Error(`POST #${String(callIndex)} carried no request body`)
  }
  return Reflect.get(body, field)
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
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLineAndRecord('400')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // The hosts (InvoiceDetailPage / SalesOrderDetailPage / PurchaseOrderDetailPage)
    // render the modal gated on partner_id, so closing only flips isOpen — the
    // component stays MOUNTED across the close/reopen cycle.
    rerender(<RecordPaymentModal isOpen={false} onClose={onClose} prefill={makePrefill()} />)
    rerender(<RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />)

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

    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={makePrefill()} />, {
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

    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={makePrefill()} />, {
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

  it('does NOT rotate the key when the PARENT re-renders with a fresh prefill object while the modal stays open', async () => {
    // Gate r2 F1 / B1'. All three hosts build `prefill` as an inline object
    // literal, so its identity changes on every parent re-render. Before the
    // fix the per-open rotation lived in the effect whose deps include
    // `prefill`, so a reconnect-driven refetch — caused by the very lost
    // response the key exists to survive (`lib/queryClient.ts:9`
    // refetchOnReconnect, WebSocketReconnectProvider invalidating all queries)
    // — rotated the key mid-intent and let the retry book a SECOND payment.
    //
    // The rotation must fire on the closed -> open TRANSITION only.
    const uuids = installUuidRecorder()
    try {
      mockApiPost.mockRejectedValueOnce(new Error('network error'))
      mockApiPost.mockResolvedValueOnce({
        payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '400.00' }],
        document: { id: 'doc-1', document_number: 'INV-1', balance_due: '600.00', status: 'partially_paid' },
        excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
      })

      const onClose = vi.fn()
      const { rerender } = render(
        <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
        { wrapper: wrapper(createClient()) },
      )

      await confirmLineAndRecord('400')
      await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

      const mintedBeforeRerender = uuids.minted.length
      // isOpen is UNCHANGED — only the parent re-rendered, exactly as a
      // refetch of the host document does.
      await act(async () => {
        rerender(<RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />)
        await Promise.resolve()
      })
      const mintedDuringRerender = uuids.minted.slice(mintedBeforeRerender)

      // T12b: the reset effect now fires on the closed -> open TRANSITION only,
      // so a fresh `prefill` identity while the modal stays open is a complete
      // no-op for the form. Nothing is minted in this window — neither a
      // replacement payment-line id (the old wipe, gate r3 m8) nor a rotated
      // idempotency key (the r2 F1 defect).
      expect(mintedDuringRerender).toHaveLength(0)

      // Money path, now readable directly because the line SURVIVES the
      // re-render: the operator retries the literally unchanged batch, so the
      // POST must carry the key of the first attempt and replay server-side.
      await pressRecord()
      await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })
      expect(postedIdempotencyKey(1)).toBe(postedIdempotencyKey(0))
      expect(mintedDuringRerender).not.toContain(postedIdempotencyKey(1))
    } finally {
      uuids.restore()
    }
  })

  it('does NOT rotate the key when the payload is edited BEFORE any submit attempt', async () => {
    // Falsifier for the `if (!hadFailedAttemptRef.current) return` guard in
    // startNewIntentOnPayloadEdit. Without it the mechanism is keystroke-scoped
    // rather than intent-scoped: filling the form would mint a fresh key on
    // every edit, and the very first submit would race its own rotation.
    const uuids = installUuidRecorder()
    try {
      // Rejected so the modal stays on the form (no success panel churn); the
      // assertion only reads the key the FIRST submit carried.
      mockApiPost.mockRejectedValueOnce(new Error('network error'))

      render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={makePrefill()} />, {
        wrapper: wrapper(createClient()),
      })

      await screen.findByRole('option', { name: 'Cash' })
      const mintedAtMount = [...uuids.minted]

      // Operator edits, no attempt yet: method, amount, repository, confirm.
      await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method *'), 'method-1')
      await userEvent.type(screen.getByLabelText('treasury:payments.amount *'), '400')
      await userEvent.selectOptions(screen.getByLabelText('treasury:repositories.title'), 'repo-1')
      await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))

      expect(uuids.minted).toEqual(mintedAtMount)

      await act(async () => {
        await userEvent.click(screen.getByRole('button', { name: /treasury:payments.record/ }))
      })
      await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

      // The first submit carries the key that was live at mount.
      expect(mintedAtMount).toContain(postedIdempotencyKey(0))
    } finally {
      uuids.restore()
    }
  })
})

describe('RecordPaymentModal prefill identity does not wipe in-progress entry', () => {
  // Gate r3 item 2 (treasury), raised P1 pre-production: the form-reset effect
  // used to depend on `prefill`, which all three hosts build as an inline
  // object literal (`InvoiceDetailPage.tsx:899`, `SalesOrderDetailPage.tsx:784`,
  // `PurchaseOrderDetailPage.tsx:713`). A reconnect refetch
  // (`lib/queryClient.ts:9` refetchOnReconnect + WebSocketReconnectProvider
  // invalidating every active query) therefore discarded the operator's
  // confirmed payment lines, date and notes mid-intent and forced a re-entry —
  // and that re-entry legitimately rotates the idempotency key, so a payment
  // that had already committed behind a lost response was booked a SECOND time.
  // The fix is to the WIPE, not to the host prop identity.

  it("keeps the operator's confirmed line, notes and date when the parent re-renders with a NEW prefill object of the SAME values", async () => {
    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLine('400')
    await userEvent.clear(screen.getByLabelText('treasury:payments.notes'))
    await userEvent.type(screen.getByLabelText('treasury:payments.notes'), 'cheque 88213')
    fireEvent.change(screen.getByLabelText('treasury:payments.date *'), {
      target: { value: '2026-09-01' },
    })

    expect(screen.getByText('treasury:unifiedPayment.lineConfirmed')).toBeInTheDocument()

    await act(async () => {
      rerender(<RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />)
      await Promise.resolve()
    })

    expect(screen.getByText('treasury:unifiedPayment.lineConfirmed')).toBeInTheDocument()
    expect(screen.getByLabelText('treasury:payments.amount *')).toHaveValue(400)
    expect(screen.getByLabelText('treasury:payments.notes')).toHaveValue('cheque 88213')
    expect(screen.getByLabelText('treasury:payments.date *')).toHaveValue('2026-09-01')
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('keeps the in-progress line and shows the NEW outstanding amount when prefill.amount changes mid-entry', async () => {
    // The first payment committed, so the refetch brings back a smaller
    // outstanding. The read-only balance display must follow the server; the
    // operator's entry must NOT be touched and must NOT be silently clamped —
    // over-allocation stays the existing excess/validation path's job.
    //
    // SAME document: this is the T12b cure itself, and the uuid window proves
    // the r1 BLOCKER-1 fix did not over-reach — a money-only prefill change on
    // the same intent must mint neither a replacement line id nor a new key.
    const uuids = installUuidRecorder()
    try {
      const onClose = vi.fn()
      const { rerender } = render(
        <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
        { wrapper: wrapper(createClient()) },
      )

      await confirmLine('400')
      expect(screen.getByText('100')).toBeInTheDocument()
      const mintedBeforeRerender = uuids.minted.length

      await act(async () => {
        rerender(
          <RecordPaymentModal isOpen onClose={onClose} prefill={{ ...makePrefill(), amount: 600 }} />,
        )
        await Promise.resolve()
      })

      expect(screen.getByText('treasury:unifiedPayment.lineConfirmed')).toBeInTheDocument()
      expect(screen.getByLabelText('treasury:payments.amount *')).toHaveValue(400)
      expect(screen.getByText('600')).toBeInTheDocument()
      expect(screen.queryByText('100')).not.toBeInTheDocument()
      expect(uuids.minted.slice(mintedBeforeRerender)).toHaveLength(0)
    } finally {
      uuids.restore()
    }
  })

  it('resets the form and rotates the key when prefill switches to a DIFFERENT document while the modal stays open', async () => {
    // Gate r1 BLOCKER-1. `sales-orders` (`routes/index.tsx:699-706`) and
    // `purchase-orders` (`:948-955`) are NOT wrapped in `KeyedByRouteId` (the
    // invoice host is, `:739-750`), so a route-param change — browser Back,
    // two-finger swipe-back — swaps `prefill` under a modal that stays open and
    // mounted. The POST body reads `prefill.partner_id` / `prefill.document_id`
    // at SUBMIT time, so a surviving line from document A would be booked
    // against document B and partner B. A document swap is a NEW intent: the
    // form must re-seed and the idempotency key must rotate.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({
      payments: [{ id: 'payment-2', payment_number: 'PAY-2', amount: '50.00' }],
      document: { id: 'doc-2', document_number: 'INV-2', balance_due: '0.00', status: 'paid' },
      excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
    })

    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    // Document A: the operator confirms 400 and the response is lost.
    await confirmLineAndRecord('400')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    expect(screen.getByText('treasury:unifiedPayment.lineConfirmed')).toBeInTheDocument()

    // Route param changes to document B while the overlay is still open.
    await act(async () => {
      rerender(
        <RecordPaymentModal
          isOpen
          onClose={onClose}
          prefill={{
            partner_id: 'partner-2',
            partner_name: 'Partner B',
            amount: 50,
            reference: 'INV-2',
            document_id: 'doc-2',
            document_type: 'invoice' as const,
          }}
        />,
      )
      await Promise.resolve()
    })

    expect(screen.queryByText('treasury:unifiedPayment.lineConfirmed')).not.toBeInTheDocument()
    expect(screen.getByLabelText('treasury:payments.amount *')).toHaveValue(null)
    expect(screen.getByLabelText('treasury:payments.notes')).toHaveValue(
      'Payment for invoice INV-2',
    )
    expect(screen.getByRole('button', { name: /treasury:payments.record/ })).toBeDisabled()

    // Whatever the operator does next must never carry document A's line, and
    // must not replay document A's committed batch under its key.
    await confirmLine('50')
    await pressRecord()
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    expect(postedField(1, 'document_id')).toBe('doc-2')
    expect(postedField(1, 'partner_id')).toBe('partner-2')
    expect(postedField(1, 'payments')).toEqual([
      { payment_method_id: 'method-1', repository_id: 'repo-1', amount: '50' },
    ])
    expect(postedIdempotencyKey(1)).not.toBe(postedIdempotencyKey(0))
  })

  it('warns that the payment may already have been recorded when the outstanding drops to 0 after a failed attempt', async () => {
    // Gate r1 NB-1. The ONLY protection against a double payment is retrying
    // literally unchanged (any payload edit rotates the key, `:161-165`), yet
    // after a lost response the reconnect refetch repaints "balance due 0 /
    // excess N" with nothing saying the first attempt may have committed —
    // the state that most invites the one action that defeats the protection.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))

    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLineAndRecord('100')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    // Before the refetch the outstanding still stands: no reason to warn yet.
    expect(screen.queryByText('treasury:unifiedPayment.possiblyRecorded')).not.toBeInTheDocument()

    // The payment DID commit — the refetch brings the outstanding back as 0.
    await act(async () => {
      rerender(
        <RecordPaymentModal isOpen onClose={onClose} prefill={{ ...makePrefill(), amount: 0 }} />,
      )
      await Promise.resolve()
    })

    expect(screen.getByText('treasury:unifiedPayment.possiblyRecorded')).toBeInTheDocument()
  })

  it('DOES reset the form on the next open transition (close -> reopen)', async () => {
    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLine('400')
    await userEvent.clear(screen.getByLabelText('treasury:payments.notes'))
    await userEvent.type(screen.getByLabelText('treasury:payments.notes'), 'cheque 88213')
    expect(screen.getByText('treasury:unifiedPayment.lineConfirmed')).toBeInTheDocument()

    // Two separate commits: React batches everything inside one act(), which
    // would collapse the close and the reopen into a single effect run and
    // never exercise the transition.
    await act(async () => {
      rerender(<RecordPaymentModal isOpen={false} onClose={onClose} prefill={makePrefill()} />)
      await Promise.resolve()
    })
    await act(async () => {
      rerender(<RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />)
      await Promise.resolve()
    })

    await screen.findByRole('option', { name: 'Cash' })
    expect(screen.queryByText('treasury:unifiedPayment.lineConfirmed')).not.toBeInTheDocument()
    expect(screen.getByLabelText('treasury:payments.amount *')).toHaveValue(null)
    expect(screen.getByLabelText('treasury:payments.notes')).toHaveValue(
      'Payment for invoice INV-1',
    )
  })
  it('clears the failure banners when the operator edits the payload after a failure (the key has rotated)', async () => {
    // Gate r2 NB-7. `mutation.isError` outlives the intent it belongs to —
    // nothing in TanStack Query clears it. The banner's advice ("press Record
    // without changing anything to confirm") is only true while the FAILED
    // attempt's key is still in the box; the first payload edit rotates it
    // (`startNewIntentOnPayloadEdit`), after which Record posts a NEW key and
    // books a SECOND payment. So the banners must go with the rotation.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))

    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLineAndRecord('100')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // The lost response had committed: the refetch brings the outstanding to 0.
    await act(async () => {
      rerender(
        <RecordPaymentModal isOpen onClose={onClose} prefill={{ ...makePrefill(), amount: 0 }} />,
      )
      await Promise.resolve()
    })
    expect(screen.getByText('treasury:unifiedPayment.possiblyRecorded')).toBeInTheDocument()
    expect(screen.getByText('network error')).toBeInTheDocument()

    // The operator changes the payment date — a payload edit, i.e. a NEW intent.
    await act(async () => {
      fireEvent.change(screen.getByLabelText('treasury:payments.date *'), {
        target: { value: '2026-09-02' },
      })
      await Promise.resolve()
    })

    // `mutation.reset()` notifies through TanStack's notifyManager, which
    // batches outside the rerender commit — so the clear lands on the next tick.
    await waitFor(() => {
      expect(screen.queryByText('treasury:unifiedPayment.possiblyRecorded')).not.toBeInTheDocument()
    })
    expect(screen.queryByText('network error')).not.toBeInTheDocument()
    // The confirmed line still stands and the outstanding is still 0, so the two
    // other halves of the banner gate are untouched: only the intent moved on.
    expect(screen.getByText('treasury:unifiedPayment.lineConfirmed')).toBeInTheDocument()
  })

  it('clears the failure banners when the document swaps under the open modal', async () => {
    // Gate r2 NB-7, second half: a failure recorded against document A must not
    // paint a red error over document B. The re-seed already clears the
    // confirmed line (so the "possibly recorded" gate falls on its own), but the
    // raw error banner is gated on `mutation.isError` ALONE — it is the
    // load-bearing assertion here.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))

    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLineAndRecord('100')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    expect(screen.getByText('network error')).toBeInTheDocument()

    await act(async () => {
      rerender(
        <RecordPaymentModal
          isOpen
          onClose={onClose}
          prefill={{
            partner_id: 'partner-2',
            partner_name: 'Partner B',
            amount: 50,
            reference: 'INV-2',
            document_id: 'doc-2',
            document_type: 'invoice' as const,
          }}
        />,
      )
      await Promise.resolve()
    })

    await waitFor(() => {
      expect(screen.queryByText('network error')).not.toBeInTheDocument()
    })
    expect(screen.queryByText('treasury:unifiedPayment.possiblyRecorded')).not.toBeInTheDocument()
  })

  it('KEEPS the failure banners while the intent is unchanged (retrying unchanged is still the safe move)', async () => {
    // The control for the two tests above: the cure must scope the banners to
    // the intent, not suppress them. A same-intent re-render — which is exactly
    // what the reconnect refetch produces — leaves the key alone, so the advice
    // stays true and the banners must stay up.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))

    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLineAndRecord('100')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    await act(async () => {
      rerender(
        <RecordPaymentModal isOpen onClose={onClose} prefill={{ ...makePrefill(), amount: 0 }} />,
      )
      await Promise.resolve()
    })
    await act(async () => {
      rerender(
        <RecordPaymentModal isOpen onClose={onClose} prefill={{ ...makePrefill(), amount: 0 }} />,
      )
      await Promise.resolve()
    })

    expect(screen.getByText('treasury:unifiedPayment.possiblyRecorded')).toBeInTheDocument()
    expect(screen.getByText('network error')).toBeInTheDocument()
  })

  it('keeps later submits working after a successful submit (reset only on error — a bare mutation.reset() on rotation would drop onSettled and wedge submitLockRef)', async () => {
    // Gate B1. The `if (mutation.isError)` guard on the rotation reset
    // (`RecordPaymentModal.tsx:456`) is load-bearing, and nothing else in this
    // suite reds when it is dropped. Mechanism, in @tanstack/query-core:
    //  - `Mutation.execute()` awaits `options.onSuccess(...)` BEFORE dispatching
    //    the success action, and `onSuccess` here rotates the key as its FIRST
    //    statement (`:406`) and only THEN awaits
    //    `Promise.all([...invalidateQueries])` (`:407-422`). The whole await
    //    window therefore runs while the mutation is still `pending` and the
    //    per-`mutate` `onSettled` has not fired.
    //  - `MutationObserver.reset()` does `removeObserver(this)`, and the
    //    per-`mutate` `onSuccess`/`onError`/`onSettled` are only invoked through
    //    `#notify()` while the observer is still attached.
    // So an UNGUARDED `mutation.reset()` on that rotation DROPS the `onSettled`
    // that releases `submitLockRef` (`:469-472`): `handleSubmit` early-returns
    // forever after, and every later submit from the same mounted modal is
    // silently swallowed while the operator sees the success panel.
    //
    // The window only exists at production latency: the file's default mocks
    // resolve the invalidation refetches on a MICROTASK, so React never flushes
    // the rotation's render + passive effect before the success dispatch and the
    // probe passes either way. Here they resolve on a MACROTASK.
    mockLookupResponsesWithLatency(20)
    mockApiPost.mockResolvedValue({
      payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '400.00' }],
      document: { id: 'doc-1', document_number: 'INV-1', balance_due: '600.00', status: 'partially_paid' },
      excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
    })

    const onClose = vi.fn()
    const { rerender } = render(
      <RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />,
      { wrapper: wrapper(createClient()) },
    )

    await confirmLineAndRecord('400')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // The awaited invalidation refetches are still in flight: give React the
    // whole window so the rotation commits while the mutation is pending.
    await settleFor(80)
    expect(await screen.findByText('treasury:payments.recordedSuccess')).toBeInTheDocument()

    // Close and reopen the STILL-MOUNTED modal (the hosts gate on partner_id, so
    // closing only flips isOpen) — a second, unrelated payment intent.
    await act(async () => {
      rerender(<RecordPaymentModal isOpen={false} onClose={onClose} prefill={makePrefill()} />)
      await Promise.resolve()
    })
    await act(async () => {
      rerender(<RecordPaymentModal isOpen onClose={onClose} prefill={makePrefill()} />)
      await Promise.resolve()
    })

    // The money assertion: the second submit must actually POST. With a bare
    // reset() this waitFor reds with "expected 2 times, but got 1 times".
    await confirmLineAndRecord('200')
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })
    expect(postedIdempotencyKey(1)).not.toBe(postedIdempotencyKey(0))

    // Drain the second submit's own invalidation window inside act().
    await settleFor(80)
  })
})
