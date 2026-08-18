import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { defaultCompanyConfig, mechanicCompanyConfig } from '@/test/fixtures/companyConfig'
import { DeliveryNoteConsolidation } from './components/DeliveryNoteConsolidation'
import { useAuthStore } from '@/stores/authStore'

const mockMutateAsync = vi.fn()

vi.mock('./hooks/useDeliveryNotes', () => ({
  calculateConsolidationTotals: () => ({ total: '50.000', lineCount: 1 }),
  groupDeliveryNotesByPartner: (deliveryNotes: { partner_id: string }[]) => new Map([['partner-1', deliveryNotes]]),
  useConsolidateDeliveryNotes: () => ({ isPending: false, mutateAsync: mockMutateAsync }),
  useInvoiceableDeliveryNotes: () => ({
    data: [{
      id: 'delivery-note-1',
      partner_id: 'partner-1',
      partner_name: 'Customer',
      document_number: 'DN-001',
      document_date: '2026-08-12',
      currency: 'TND',
      total: '50.000',
    }],
    error: null,
    isLoading: false,
  }),
}))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

function setPermissions(permissions: string[]) {
  const user = useAuthStore.getState().user
  if (user === null) {
    throw new Error('Expected an authenticated test user')
  }

  act(() => {
    useAuthStore.getState().setUser({ ...user, permissions })
  })
}

function selectDeliveryNote() {
  const checkbox = screen.getAllByRole('checkbox')[0]

  act(() => {
    fireEvent.click(checkbox)
  })
}

describe('DeliveryNoteConsolidation gates', () => {
  beforeEach(() => {
    mockMutateAsync.mockReset()
    act(() => {
      seedAuth()
    })
  })

  afterEach(() => {
    act(() => {
      resetAuth()
    })
  })

  it('hides the inline consolidation action when Sales is disabled even for an invoice creator', () => {
    setPermissions(['invoices.create'])

    renderWithProviders(<DeliveryNoteConsolidation />, {
      companyConfig: defaultCompanyConfig,
    })

    selectDeliveryNote()

    expect(screen.queryByRole('button', { name: /create invoice/i })).not.toBeInTheDocument()
    expect(mockMutateAsync).not.toHaveBeenCalled()
  })

  it('hides consolidation from a delivery reader but shows it to an invoice creator when Sales is enabled', () => {
    setPermissions(['deliveries.view'])
    const { rerender } = renderWithProviders(<DeliveryNoteConsolidation />, {
      companyConfig: mechanicCompanyConfig,
    })

    selectDeliveryNote()
    expect(screen.queryByRole('button', { name: /create invoice/i })).not.toBeInTheDocument()

    setPermissions(['invoices.create'])
    act(() => {
      rerender(<DeliveryNoteConsolidation />)
    })

    expect(screen.getByRole('button', { name: /create invoice/i })).toBeInTheDocument()
  })
})
