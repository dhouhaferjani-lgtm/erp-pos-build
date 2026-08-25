import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import type { ReactElement, ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { toast } from 'sonner'

import { useFinalizeCounting, useManualOverride } from '../queries'

/**
 * LEDGER C-14(iv) — gate r1 IMPORTANT-1.
 *
 * The backend now renders `COUNTING_TRANSITION_REFUSED` (and C-14(iii)'s
 * `BUSINESS_ERROR` unresolved-items refusal) in the requesting tenant's locale.
 * The counting feature's mutation handlers interpolated the RAW AxiosError's
 * `.message` into the toast, which is always "Request failed with status code
 * 422" — `apiPost` only unwraps the SUCCESS body and the response interceptor
 * re-rejects the error unchanged (`lib/api.ts:361`). So the localised message
 * was discarded on the only surface that raises it, and C-14(iv) delivered
 * nothing to a human.
 *
 * The house helper that DOES read the envelope is `getErrorMessage()`
 * (`lib/api.ts:83-98`, fallback chain `data.error.message ?? data.message ??
 * error.message`) — the same value `handleLinkReceipts` surfaces in purchases.
 */

const mockManualOverride = vi.hoisted(() => vi.fn())
const mockFinalize = vi.hoisted(() => vi.fn())

vi.mock('../countingApi', () => ({
  countingApi: {
    manualOverride: mockManualOverride,
    finalize: mockFinalize,
  },
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts ? `${key}:${JSON.stringify(opts)}` : key,
    i18n: { language: 'fr' },
  }),
}))

const FRENCH_REFUSAL = 'Ce comptage est « Finalisé » et ne peut pas passer à « En attente de révision ».'
const UNRESOLVED_REFUSAL = 'Cannot finalize: 1 item still pending resolution.'

/**
 * A real AxiosError, built the way the interceptor re-rejects one
 * (`lib/api.ts:361`): the 422 envelope lives on `response.data`, and
 * `error.message` is axios's own generic string.
 */
function axios422(body: unknown): AxiosError {
  const headers = new AxiosHeaders()
  const config: InternalAxiosRequestConfig = { headers }

  return new AxiosError('Request failed with status code 422', 'ERR_BAD_REQUEST', config, {}, {
    data: body,
    status: 422,
    statusText: 'Unprocessable Content',
    headers,
    config,
  })
}

function makeWrapper(): ({ children }: { children: ReactNode }) => ReactElement {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function lastErrorToast(): string {
  const calls = vi.mocked(toast.error).mock.calls
  expect(calls.length).toBeGreaterThan(0)
  const arg: unknown = calls[calls.length - 1][0]
  return typeof arg === 'string' ? arg : JSON.stringify(arg)
}

describe('inventory-counting mutations surface the BACKEND error message', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('useManualOverride shows the localised COUNTING_TRANSITION_REFUSED message, not the axios string', async () => {
    mockManualOverride.mockRejectedValue(
      axios422({
        error: {
          code: 'COUNTING_TRANSITION_REFUSED',
          message: FRENCH_REFUSAL,
          counting_id: 'c-1',
          current_status: 'finalized',
          attempted_status: 'pending_review',
        },
      }),
    )

    const { result } = renderHook(() => useManualOverride('c-1'), { wrapper: makeWrapper() })

    result.current.mutate({ itemId: 'i-1', quantity: '12.0000', notes: 'late' })

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalled()
    })

    const message = lastErrorToast()
    expect(message).toContain(FRENCH_REFUSAL)
    expect(message).not.toContain('Request failed with status code 422')
  })

  it('useFinalizeCounting shows the BUSINESS_ERROR unresolved-items message, not the axios string', async () => {
    mockFinalize.mockRejectedValue(
      axios422({
        error: {
          code: 'BUSINESS_ERROR',
          message: UNRESOLVED_REFUSAL,
        },
      }),
    )

    const { result } = renderHook(() => useFinalizeCounting(), { wrapper: makeWrapper() })

    result.current.mutate({
      id: 'c-1',
      acknowledgeTerminalSyncRisk: false,
      terminalSyncHealthSignature: null,
    })

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalled()
    })

    const message = lastErrorToast()
    expect(message).toContain(UNRESOLVED_REFUSAL)
    expect(message).not.toContain('Request failed with status code 422')
  })

  it('still shows the terminal-sync branch for its own typed code', async () => {
    mockFinalize.mockRejectedValue(
      axios422({
        error: { code: 'TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED', message: 'ignored' },
      }),
    )

    const { result } = renderHook(() => useFinalizeCounting(), { wrapper: makeWrapper() })

    result.current.mutate({
      id: 'c-1',
      acknowledgeTerminalSyncRisk: false,
      terminalSyncHealthSignature: null,
    })

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalledWith('counting.review.terminalSync.changed')
    })
  })
})
