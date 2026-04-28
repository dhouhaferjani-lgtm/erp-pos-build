/**
 * CloseWithWriteoffSection — visibility + happy path component test.
 * Mocks useToleranceSettings and the close-with-tolerance API.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CloseWithWriteoffSection } from './CloseWithWriteoffSection'
import * as toleranceHooks from '@/features/treasury/hooks/useSmartPayment'
import * as closeApi from '../api/closeWithTolerance'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        'invoice.closeWithWriteoff.calloutTitle': 'Residual within tolerance',
        'invoice.closeWithWriteoff.calloutMessage': '{{amount}} remains',
        'invoice.closeWithWriteoff.button': 'Close with write-off ({{amount}})',
        'invoice.closeWithWriteoff.dialog.title': 'Close invoice with write-off',
        'invoice.closeWithWriteoff.dialog.message': '{{amount}} will be written off',
        'invoice.closeWithWriteoff.dialog.confirm': 'Close invoice',
        'invoice.closeWithWriteoff.dialog.cancel': 'Cancel',
        'invoice.closeWithWriteoff.success': 'Invoice closed — {{amount}} written off',
        'invoice.closeWithWriteoff.error.toleranceExceeded': 'Balance exceeds tolerance',
        'invoice.closeWithWriteoff.error.alreadyPaid': 'Already settled',
        'invoice.closeWithWriteoff.error.generic': 'Could not close',
        'actions.cancel': 'Cancel',
        'actions.confirm': 'Confirm',
        'actions.processing': 'Processing...',
      }
      let result = translations[key] ?? key
      if (params) {
        Object.entries(params).forEach(([k, v]) => {
          result = result.replace(`{{${k}}}`, String(v))
        })
      }
      return result
    },
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const enabledFRSettings = {
  enabled: true as const,
  percentage: '0.0050',
  max_amount: '0.500',
  source: 'country' as const,
}

const disabledSettings = {
  enabled: false as const,
  percentage: '0.0050',
  max_amount: '0.500',
  source: 'system_default' as const,
}

const createQueryClient = () =>
  new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

function renderSection(props: Partial<React.ComponentProps<typeof CloseWithWriteoffSection>> = {}) {
  const merged: React.ComponentProps<typeof CloseWithWriteoffSection> = {
    invoiceId: 'inv-1',
    invoiceTotal: '100.300',
    balanceDue: '0.300',
    currency: 'EUR',
    invoiceStatus: 'posted',
    ...props,
  }
  const queryClient = createQueryClient()
  return render(
    <QueryClientProvider client={queryClient}>
      <CloseWithWriteoffSection {...merged} />
    </QueryClientProvider>
  )
}

describe('CloseWithWriteoffSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.spyOn(toleranceHooks, 'useToleranceSettings').mockReturnValue({
      data: enabledFRSettings,
      isLoading: false,
      error: null,
    } as ReturnType<typeof toleranceHooks.useToleranceSettings>)
  })

  it('renders nothing when invoice status is not posted', () => {
    renderSection({ invoiceStatus: 'draft' })
    expect(screen.queryByTestId('close-with-writeoff-button')).not.toBeInTheDocument()
  })

  it('renders nothing when balance is zero', () => {
    renderSection({ balanceDue: '0.000' })
    expect(screen.queryByTestId('close-with-writeoff-button')).not.toBeInTheDocument()
  })

  it('renders nothing when tolerance settings are disabled', () => {
    vi.spyOn(toleranceHooks, 'useToleranceSettings').mockReturnValue({
      data: disabledSettings,
      isLoading: false,
      error: null,
    } as ReturnType<typeof toleranceHooks.useToleranceSettings>)
    renderSection()
    expect(screen.queryByTestId('close-with-writeoff-button')).not.toBeInTheDocument()
  })

  it('renders nothing when balance exceeds the absolute tolerance threshold', () => {
    renderSection({ balanceDue: '5.000', invoiceTotal: '105.000' })
    expect(screen.queryByTestId('close-with-writeoff-button')).not.toBeInTheDocument()
  })

  it('renders nothing when balance equals the absolute tolerance threshold (strict <)', () => {
    // 0.5% of 1000 = 5.0; max_amount 0.5 binds at exactly 0.5.
    renderSection({ balanceDue: '0.500', invoiceTotal: '1000.500' })
    expect(screen.queryByTestId('close-with-writeoff-button')).not.toBeInTheDocument()
  })

  it('renders the button when within both thresholds', () => {
    renderSection({ balanceDue: '0.300', invoiceTotal: '100.300' })
    expect(screen.getByTestId('close-with-writeoff-button')).toBeInTheDocument()
  })

  it('opens the confirm dialog on button click and calls API on confirm', async () => {
    const apiSpy = vi.spyOn(closeApi, 'closeInvoiceWithTolerance').mockResolvedValue({
      data: {},
      meta: {
        tolerance_writeoff: { amount: '0.300', gl_entry_id: 'gl-1' },
        timestamp: '2026-04-26T00:00:00Z',
      },
    })

    renderSection({ invoiceId: 'inv-42', balanceDue: '0.300', invoiceTotal: '100.300' })

    fireEvent.click(screen.getByTestId('close-with-writeoff-button'))
    expect(screen.getByText('Close invoice with write-off')).toBeInTheDocument()

    fireEvent.click(screen.getByText('Close invoice'))

    await waitFor(() => {
      expect(apiSpy).toHaveBeenCalledTimes(1)
    })
    expect(apiSpy).toHaveBeenCalledWith('inv-42')
  })
})
