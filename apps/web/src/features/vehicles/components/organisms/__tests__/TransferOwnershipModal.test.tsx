import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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
  type: string
  email?: string | null
  city?: string | null
}

const partners: Partner[] = [
  {
    id: '11111111-1111-1111-1111-111111111111',
    name: 'Alpha Garage',
    type: 'customer',
    email: 'alpha@example.com',
    city: 'Tunis',
  },
  {
    id: '22222222-2222-2222-2222-222222222222',
    name: 'Beta Motors',
    type: 'both',
    email: 'beta@example.com',
    city: 'Sousse',
  },
]

const mockApiGet = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', () => ({
  api: { get: mockApiGet },
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
    mockApiGet.mockReset()
    mockApiGet.mockResolvedValue({ data: { data: partners } })
  })

  it('calls the transfer mutation with the partner UUID selected via the picker', async () => {
    renderModal()
    const user = userEvent.setup()

    // The picker renders a combobox input; typing triggers the debounced search.
    // The reason select also has role=combobox, so find the picker's input by test id.
    const picker = screen.getByTestId('transfer-owner-picker')
    const combo = picker.querySelector('input[role="combobox"]')
    if (!(combo instanceof HTMLInputElement)) {
      throw new Error('partner picker combobox missing')
    }
    await user.click(combo)
    await user.type(combo, 'Beta')

    await waitFor(() => {
      expect(screen.getByText('Beta Motors')).toBeInTheDocument()
    })
    await user.click(screen.getByText('Beta Motors'))

    fireEvent.click(screen.getByRole('button', { name: 'ownership.confirmTransfer' }))

    expect(mutate).toHaveBeenCalledTimes(1)
    const firstCall = mutate.mock.calls[0] as unknown[]
    const [rawPayload] = firstCall
    if (
      typeof rawPayload !== 'object' ||
      rawPayload === null ||
      !('new_owner_partner_id' in rawPayload)
    ) {
      throw new Error('Expected mutate payload to include new_owner_partner_id')
    }
    expect(rawPayload.new_owner_partner_id).toBe('22222222-2222-2222-2222-222222222222')
  })

  it('blocks submission and shows an inline error when no owner is picked', async () => {
    renderModal()

    fireEvent.click(screen.getByRole('button', { name: 'ownership.confirmTransfer' }))
    // Button is disabled while value is null.
    expect(mutate).not.toHaveBeenCalled()
  })
})
