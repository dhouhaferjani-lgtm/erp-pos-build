import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen, waitFor } from '@testing-library/react'
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

  it('hides the inline consolidation action from a delivery reader when Sales is enabled', () => {
    setPermissions(['deliveries.view'])
    renderWithProviders(<DeliveryNoteConsolidation />, {
      companyConfig: mechanicCompanyConfig,
    })

    selectDeliveryNote()
    expect(screen.queryByRole('button', { name: /create invoice/i })).not.toBeInTheDocument()
    expect(mockMutateAsync).not.toHaveBeenCalled()
  })

  it('executes consolidation with the selected delivery-note ids for an invoice creator when Sales is enabled', async () => {
    const onSuccess = vi.fn()
    mockMutateAsync.mockResolvedValue({ data: { id: 'invoice-1' } })
    setPermissions(['invoices.create'])

    renderWithProviders(<DeliveryNoteConsolidation onSuccess={onSuccess} />, {
      companyConfig: mechanicCompanyConfig,
    })

    selectDeliveryNote()
    act(() => {
      fireEvent.click(screen.getByRole('button', { name: /create invoice/i }))
    })

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalledWith(['delivery-note-1'])
    })
    expect(onSuccess).toHaveBeenCalledWith('invoice-1')
  })
})
