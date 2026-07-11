import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CreateTransferDialog } from '../components/CreateTransferDialog'
import { makeReplenishmentLine } from './replenishmentTestFixtures'

const mutateAsync = vi.fn()
let permissionAllowed = true

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))
vi.mock('@/components/auth', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => permissionAllowed ? children : null,
}))
vi.mock('@/features/locations/hooks/useLocations', () => ({
  useLocations: () => ({ data: [
    { id: 'warehouse', name: 'Warehouse', type: 'warehouse', isActive: true },
    { id: 'shop-a', name: 'Shop A', type: 'shop', isActive: true },
    { id: 'shop-b', name: 'Shop B', type: 'shop', isActive: true },
  ] }),
}))
vi.mock('../api/queries', () => ({
  useCreateTransferAction: () => ({ mutateAsync, isPending: false }),
}))

const selected = [
  makeReplenishmentLine({ id: 'request-a', location_id: 'shop-a', location_name: 'Shop A', requested_qty: null }),
  makeReplenishmentLine({ id: 'request-b', location_id: 'shop-b', location_name: 'Shop B', requested_qty: '2.5000' }),
]

describe('CreateTransferDialog', () => {
  beforeEach(() => {
    permissionAllowed = true
    mutateAsync.mockReset().mockResolvedValue({ transfer_ids: ['transfer-a', 'transfer-b'] })
  })

  it('gates required fields and submits string quantities grouped by destination', async () => {
    const user = userEvent.setup()
    render(<CreateTransferDialog selected={selected} isOpen onClose={vi.fn()} />)

    const submit = screen.getByRole('button', { name: 'dialog.submit' })
    expect(submit).toBeDisabled()
    expect(screen.getByText('dialog.transfer_preview')).toBeInTheDocument()
    await user.selectOptions(screen.getByRole('combobox', { name: 'dialog.source' }), 'warehouse')
    expect(submit).toBeEnabled()
    await user.click(submit)

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        source_location_id: 'warehouse',
        lines: [
          { request_id: 'request-a', quantity: '1' },
          { request_id: 'request-b', quantity: '2.5000' },
        ],
      })
    })
  })

  it('does not render without replenishment.process permission', () => {
    permissionAllowed = false
    render(<CreateTransferDialog selected={selected} isOpen onClose={vi.fn()} />)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
