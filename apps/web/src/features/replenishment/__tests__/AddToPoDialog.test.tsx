import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import { AddToPoDialog } from '../components/AddToPoDialog'
import { makeReplenishmentLine } from './replenishmentTestFixtures'

const mutateAsync = vi.fn()
let permissionAllowed = true

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))
vi.mock('@/components/auth', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => permissionAllowed ? children : null,
}))
vi.mock('@/components/ui/PartnerSearchSelect', () => ({
  PartnerSearchSelect: ({ onChange }: { onChange: (id: string) => void }) => (
    <button type="button" onClick={() => { onChange('supplier-1') }}>choose-supplier</button>
  ),
}))
vi.mock('@/features/locations/hooks/useLocations', () => ({
  useLocations: () => ({ data: [
    { id: 'warehouse', name: 'Warehouse', type: 'warehouse', isActive: true },
    { id: 'shop-a', name: 'Shop A', type: 'shop', isActive: true },
  ] }),
}))
vi.mock('../api/queries', () => ({
  useCreatePoAction: () => ({ mutateAsync, isPending: false }),
}))

const selected = [makeReplenishmentLine({ id: 'request-a', location_id: 'shop-a', location_name: 'Shop A', requested_qty: '3.0000' })]

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <AddToPoDialog selected={selected} isOpen onClose={vi.fn()} />
    </QueryClientProvider>,
  )
}

describe('AddToPoDialog', () => {
  beforeEach(() => {
    permissionAllowed = true
    mutateAsync.mockReset().mockResolvedValue({ document_id: 'po-new' })
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { data: [{ id: 'po-draft', document_number: 'PO-DRAFT-1' }] },
    })
  })

  it('requires a supplier, defaults to the warehouse, and submits draft PO payload strings', async () => {
    const user = userEvent.setup()
    renderDialog()

    const submit = screen.getByRole('button', { name: 'dialog.submit' })
    expect(submit).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'choose-supplier' }))
    await screen.findByRole('option', { name: 'PO-DRAFT-1' })
    await user.selectOptions(screen.getByRole('combobox', { name: 'dialog.existing_po' }), 'po-draft')
    expect(submit).toBeEnabled()
    await user.click(submit)

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        supplier_id: 'supplier-1',
        destination_location_id: 'warehouse',
        existing_document_id: 'po-draft',
        lines: [{ request_id: 'request-a', quantity: '3.0000' }],
      })
    })
  })

  it('does not render without replenishment.process permission', () => {
    permissionAllowed = false
    renderDialog()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
