import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { TransferOwnershipModal } from '../TransferOwnershipModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: { defaultValue?: string }) => opts?.defaultValue ?? key,
  }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: () => true,
  }),
}))

interface Partner {
  id: string
  name: string
  display_name?: string
  type: string
}

const partners: Partner[] = [
  { id: '11111111-1111-1111-1111-111111111111', name: 'Alpha Garage', display_name: 'Alpha Garage', type: 'customer' },
  { id: '22222222-2222-2222-2222-222222222222', name: 'Beta Motors', display_name: 'Beta Motors', type: 'both' },
  { id: '33333333-3333-3333-3333-333333333333', name: 'Gamma Fleet', display_name: 'Gamma Fleet', type: 'customer' },
]

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(() => Promise.resolve({ data: { data: partners } })),
  },
}))

const mutate = vi.fn()
vi.mock('../../../hooks/useTransferVehicleOwnership', () => ({
  useTransferVehicleOwnership: () => ({
    mutate,
    isPending: false,
  }),
}))

function renderModal(): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <TransferOwnershipModal
        vehicleId="veh-1"
        isOpen={true}
        onClose={() => { /* noop */ }}
      />
    </QueryClientProvider>,
  )
}

describe('TransferOwnershipModal', () => {
  beforeEach(() => {
    mutate.mockReset()
  })

  it('renders partner options fetched via the partners API', async () => {
    renderModal()

    await waitFor(() => {
      expect(screen.getByRole('option', { name: 'Alpha Garage' })).toBeInTheDocument()
    })
    expect(screen.getByRole('option', { name: 'Beta Motors' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Gamma Fleet' })).toBeInTheDocument()
  })

  it('calls the transfer mutation with the selected partner UUID', async () => {
    renderModal()

    await waitFor(() => {
      expect(screen.getByRole('option', { name: 'Beta Motors' })).toBeInTheDocument()
    })

    const selects = screen.getAllByRole('combobox')
    // First combobox is the partner picker (second is the reason)
    fireEvent.change(selects[0], {
      target: { value: '22222222-2222-2222-2222-222222222222' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'ownership.confirmTransfer' }))

    expect(mutate).toHaveBeenCalledTimes(1)
    const firstCall = mutate.mock.calls[0] as unknown[]
    const payload = firstCall[0] as { new_owner_partner_id: string }
    expect(payload.new_owner_partner_id).toBe('22222222-2222-2222-2222-222222222222')
  })
})
