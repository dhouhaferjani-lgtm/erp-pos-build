import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RejectDialog } from '../components/RejectDialog'
import { makeReplenishmentLine } from './replenishmentTestFixtures'

const mutateAsync = vi.fn()
let permissionAllowed = true

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))
vi.mock('@/components/auth', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => permissionAllowed ? children : null,
}))
vi.mock('../api/queries', () => ({
  useRejectAction: () => ({ mutateAsync, isPending: false }),
}))

const selected = [makeReplenishmentLine({ id: 'request-a' }), makeReplenishmentLine({ id: 'request-b' })]

describe('RejectDialog', () => {
  beforeEach(() => {
    permissionAllowed = true
    mutateAsync.mockReset().mockResolvedValue({ request_ids: ['request-a', 'request-b'] })
  })

  it('requires a reason and submits the selected request ids', async () => {
    const user = userEvent.setup()
    render(<RejectDialog selected={selected} isOpen onClose={vi.fn()} />)

    const submit = screen.getByRole('button', { name: 'dialog.submit' })
    expect(submit).toBeDisabled()
    await user.type(screen.getByRole('textbox', { name: 'dialog.reason' }), 'Not stocked centrally')
    expect(submit).toBeEnabled()
    await user.click(submit)

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        request_ids: ['request-a', 'request-b'],
        reason: 'Not stocked centrally',
      })
    })
  })

  it('does not render without replenishment.process permission', () => {
    permissionAllowed = false
    render(<RejectDialog selected={selected} isOpen onClose={vi.fn()} />)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
