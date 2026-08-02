import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ReceiptSettingsTab } from './ReceiptSettingsTab'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockHasPermission = vi.hoisted(() => vi.fn(() => true))

vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return {
    ...actual,
    api: { get: mockApiGet, put: mockApiPut },
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

vi.mock('../../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

const authState = { user: { tenant_id: 'tenant-1' } }
const companyState = {
  currentCompanyId: 'company-1',
  getCurrentCompany: () => ({ id: 'company-1', countryCode: 'TN' }),
}

vi.mock('../../../stores/authStore', () => ({
  useAuthStore: Object.assign(
    (sel: (s: typeof authState) => unknown) => sel(authState),
    { getState: () => authState },
  ),
}))
vi.mock('../../../stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (sel: (s: typeof companyState) => unknown) => sel(companyState),
    { getState: () => companyState },
  ),
}))

function receiptSettings() {
  return {
    receipt_header: null,
    receipt_footer: null,
    receipt_thank_you: null,
    receipt_show_vat_breakdown: true,
    receipt_show_fiscal_info: true,
    receipt_show_payment_details: true,
    receipt_show_customer: true,
    auto_print_receipts: false,
    receipt_logo: null,
  }
}

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  mockHasPermission.mockReturnValue(true)
  mockApiGet.mockResolvedValue({ data: { data: receiptSettings() } })
  mockApiPut.mockResolvedValue({ data: {} })
})

// M1/M2/M3 (gate review docs/superpowers/reviews/2026-08-02-fe-batch-gate.md): the F1
// settings.update gate on this screen (aa3fc1cc4) had zero test coverage — mutation-testing
// it (deleting `|| !canEdit`) left the whole suite green. This asserts the real behaviour:
// a caller without settings.update sees a disabled Save carrying the visible
// GoodsReceiptListPage-pattern hint (M3), and the mutation never fires on a click attempt.
describe('ReceiptSettingsTab settings.update gating', () => {
  it('disables Save and shows the read-only hint for a caller without settings.update; the mutation never fires on click', async () => {
    mockHasPermission.mockReturnValue(false)
    const user = userEvent.setup()
    render(<ReceiptSettingsTab />, { wrapper: wrapper() })

    const header = await screen.findByLabelText('settings:receipt.fields.header')
    await user.type(header, 'New header')

    const save = screen.getByRole('button', { name: 'common:actions.save' })
    expect(save).toBeDisabled()
    expect(screen.getAllByText('common:permissions.readOnlyEditHint').length).toBeGreaterThan(0)

    await user.click(save)
    expect(mockApiPut).not.toHaveBeenCalled()
  })

  it('keeps Save enabled for a caller WITH settings.update once the form is dirty (control case)', async () => {
    mockHasPermission.mockReturnValue(true)
    const user = userEvent.setup()
    render(<ReceiptSettingsTab />, { wrapper: wrapper() })

    const header = await screen.findByLabelText('settings:receipt.fields.header')
    await user.type(header, 'New header')

    const save = screen.getByRole('button', { name: 'common:actions.save' })
    expect(save).not.toBeDisabled()
    expect(screen.queryByText('common:permissions.readOnlyEditHint')).not.toBeInTheDocument()
  })
})
