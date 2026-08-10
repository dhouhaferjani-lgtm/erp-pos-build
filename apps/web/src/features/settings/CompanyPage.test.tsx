import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CompanyPage } from './CompanyPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      patch: mockApiPatch,
      post: mockApiPost,
      put: mockApiPut,
      delete: mockApiDelete,
    },
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, s?: unknown) => (typeof s === 'string' ? s : key),
  }),
  Trans: ({ i18nKey }: { i18nKey: string }) => <span>{i18nKey}</span>,
}))

vi.mock('./components/ReceiptSettingsTab', () => ({ ReceiptSettingsTab: () => null }))

const testPrimaryColor = ['#', '2563EB'].join('')

function companySettings() {
  return {
    name: 'Company A',
    legal_name: null,
    slug: 'company-a',
    tax_id: null,
    registration_number: null,
    address: { street: null, city: null, postal_code: null, country: null },
    phone: null,
    email: null,
    website: null,
    logo_url: '/logo.png',
    primary_color: testPrimaryColor,
    country_code: 'TN',
    currency_code: 'TND',
    timezone: 'Africa/Tunis',
    date_format: 'DD/MM/YYYY',
    locale: 'en',
    line_designation_override_enabled: false,
  }
}

function procurementPolicy(preset: 'complet' | 'standard' | 'leger' | null = 'standard') {
  return {
    company_id: 'company-1',
    preset,
    bill_control_mode: 'received',
    match_mode: preset === 'leger' ? 'two_way' : 'three_way',
    match_enforcement: preset === 'complet' ? 'block' : 'warn',
    variance_tolerance_percent: '2.00',
    variance_tolerance_max_amount: '1.000',
    allow_receipt_first: preset !== 'complet',
    allow_invoice_first: preset === 'leger',
    invoice_first_requires_approval: true,
  }
}

function setTenant(roles: string[] = ['admin'], permissions?: string[]) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: 'tenant-A',
      // 'admin' is the only seeded role holding settings.update
      // (permissionsMap.generated.ts) — the F1/M1 mutation gate on this
      // page requires it. Callers exercising the deny path pass a role
      // without it (e.g. []).
      roles,
      ...(permissions === undefined ? {} : { permissions }),
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
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
  setTenant()
  mockApiGet.mockImplementation((url: string) => {
    if (url === '/procurement-policies') {
      return Promise.resolve({ data: { data: procurementPolicy() } })
    }

    return Promise.resolve({ data: { data: companySettings() } })
  })
  mockApiPatch.mockResolvedValue({ data: {} })
  mockApiPost.mockResolvedValue({ data: {} })
  mockApiPut.mockResolvedValue({ data: { data: procurementPolicy('leger') } })
  mockApiDelete.mockResolvedValue({ data: {} })
})

afterEach(() => {
  resetTenant()
})

describe('CompanyPage (canonical primitives)', () => {
  it('renders exactly one h1 via PageHeader', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByLabelText('settings:company.fields.name')).toBeInTheDocument()
    })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('settings:company.title')
  })

  it('renders key fields through atoms with labels bound to inputs', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByLabelText('settings:company.fields.name')).toBeInTheDocument()
    })
    expect(screen.getByLabelText('settings:company.fields.name').tagName).toBe('INPUT')
    expect(screen.getByLabelText('settings:company.fields.email').tagName).toBe('INPUT')
    expect(screen.getByLabelText('settings:company.fields.currency').tagName).toBe('SELECT')
  })

  it('renders the save action as a real button element', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByLabelText('settings:company.fields.name')).toBeInTheDocument()
    })
    const save = screen.getByRole('button', { name: 'common:actions.save' })
    expect(save.tagName).toBe('BUTTON')
  })

  it('saves the line designation override setting', async () => {
    const user = userEvent.setup()
    render(<CompanyPage />, { wrapper: wrapper() })

    const toggle = await screen.findByRole('switch', {
      name: 'settings:company.documents.lineDesignation.label',
    })

    expect(toggle).not.toBeChecked()
    expect(screen.getByText('settings:company.documents.lineDesignation.help')).toBeInTheDocument()

    await user.click(toggle)
    await user.click(screen.getByRole('button', { name: 'common:actions.save' }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith('/settings/company', expect.objectContaining({
        line_designation_override_enabled: true,
      }))
    })
  })

  it('renders procurement preset controls in company settings', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })

    await userEvent.click(await screen.findByRole('button', { name: 'settings:company.tabs.procurement' }))

    expect(await screen.findByRole('button', { name: /settings:company.procurement.presets.complet.title/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /settings:company.procurement.presets.standard.title/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /settings:company.procurement.presets.leger.title/ })).toBeInTheDocument()
    expect(screen.getByLabelText('settings:company.procurement.fields.matchMode')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'purchases:settings.procurement.entryPoints.allowReceiptFirst' })).toBeChecked()
    expect(screen.getByRole('switch', { name: 'purchases:settings.procurement.entryPoints.allowInvoiceFirst' })).not.toBeChecked()
    expect(screen.getByRole('switch', { name: 'purchases:settings.procurement.entryPoints.invoiceFirstRequiresApproval' })).toBeChecked()
  })

  it('writes a selected procurement preset immediately', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })

    await userEvent.click(await screen.findByRole('button', { name: 'settings:company.tabs.procurement' }))
    await userEvent.click(await screen.findByRole('button', { name: /settings:company.procurement.presets.leger.title/ }))

    await waitFor(() => {
      expect(mockApiPut).toHaveBeenCalledWith('/procurement-policies', { preset: 'leger' })
    })
  })

  it('clears the procurement preset when advanced raw fields are saved', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })

    await userEvent.click(await screen.findByRole('button', { name: 'settings:company.tabs.procurement' }))
    await userEvent.selectOptions(
      await screen.findByLabelText('settings:company.procurement.fields.matchEnforcement'),
      'block',
    )
    await userEvent.click(screen.getByRole('switch', { name: 'purchases:settings.procurement.entryPoints.allowInvoiceFirst' }))
    await userEvent.click(screen.getByRole('switch', { name: 'purchases:settings.procurement.entryPoints.invoiceFirstRequiresApproval' }))
    await userEvent.click(screen.getByRole('button', { name: 'settings:company.procurement.actions.saveAdvanced' }))

    await waitFor(() => {
      expect(mockApiPut).toHaveBeenCalledWith('/procurement-policies', {
        bill_control_mode: 'received',
        match_mode: 'three_way',
        match_enforcement: 'block',
        variance_tolerance_percent: '2.00',
        variance_tolerance_max_amount: '1.000',
        allow_receipt_first: true,
        allow_invoice_first: true,
        invoice_first_requires_approval: false,
      })
    })
  })
})

// M1 (gate review docs/superpowers/reviews/2026-08-02-fe-batch-gate.md): CompanyPage was the
// largest settings.update-gated surface with NO FE affordance gate at all — five mutation
// controls (general Save, procurement Save, logo upload, logo delete) stayed fully enabled for
// a caller without settings.update, a 403 dead-end. M2: this had zero coverage anywhere, so the
// gate itself was invisible to mutation testing. M3: the visible hint (not just hover `title`)
// follows the GoodsReceiptListPage.tsx:478-497 pattern.
describe('CompanyPage settings.update gating', () => {
  it('disables the general Save and shows the read-only hint for a caller without settings.update; the mutation never fires on click', async () => {
    setTenant([])
    const user = userEvent.setup()
    render(<CompanyPage />, { wrapper: wrapper() })

    const toggle = await screen.findByRole('switch', {
      name: 'settings:company.documents.lineDesignation.label',
    })
    await user.click(toggle) // makes the form dirty

    const save = screen.getByRole('button', { name: 'common:actions.save' })
    expect(save).toBeDisabled()
    expect(screen.getAllByText('common:permissions.readOnlyEditHint').length).toBeGreaterThan(0)

    await user.click(save)
    expect(mockApiPatch).not.toHaveBeenCalled()
  })

  it('disables the procurement advanced Save and shows the read-only hint for a caller without settings.update; the mutation never fires on click', async () => {
    setTenant([])
    const user = userEvent.setup()
    render(<CompanyPage />, { wrapper: wrapper() })

    await user.click(await screen.findByRole('button', { name: 'settings:company.tabs.procurement' }))
    const save = await screen.findByRole('button', { name: 'settings:company.procurement.actions.saveAdvanced' })
    expect(save).toBeDisabled()
    expect(screen.getAllByText('common:permissions.readOnlyEditHint').length).toBeGreaterThan(0)

    await user.click(save)
    expect(mockApiPut).not.toHaveBeenCalledWith('/procurement-policies', expect.anything())
  })

  it('disables logo upload and delete and shows the read-only hint for a caller without settings.update; neither mutation fires on click', async () => {
    setTenant([])
    const user = userEvent.setup()
    render(<CompanyPage />, { wrapper: wrapper() })

    const uploadButton = await screen.findByRole('button', { name: 'settings:company.actions.replaceLogo' })
    const deleteButton = screen.getByRole('button', { name: 'common:actions.delete' })
    expect(uploadButton).toBeDisabled()
    expect(deleteButton).toBeDisabled()
    expect(screen.getAllByText('common:permissions.readOnlyEditHint').length).toBeGreaterThan(0)

    await user.click(uploadButton)
    await user.click(deleteButton)
    expect(mockApiPost).not.toHaveBeenCalled()
    expect(mockApiDelete).not.toHaveBeenCalled()
  })

  it('keeps every mutation affordance enabled for a caller WITH settings.update (control case)', async () => {
    setTenant(['admin'])
    render(<CompanyPage />, { wrapper: wrapper() })

    await screen.findByRole('button', { name: 'common:actions.save' })
    const uploadButton = screen.getByRole('button', { name: 'settings:company.actions.replaceLogo' })
    const deleteButton = screen.getByRole('button', { name: 'common:actions.delete' })
    expect(uploadButton).not.toBeDisabled()
    expect(deleteButton).not.toBeDisabled()
    expect(screen.queryByText('common:permissions.readOnlyEditHint')).not.toBeInTheDocument()

    await userEvent.click(await screen.findByRole('button', { name: 'settings:company.tabs.procurement' }))
    expect(await screen.findByRole('button', { name: 'settings:company.procurement.actions.saveAdvanced' })).not.toBeDisabled()
  })
})

describe('CompanyPage fiscal identity guards', () => {
  it('keeps country and currency immutable for an admin', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })

    expect(await screen.findByLabelText('settings:company.fields.country')).toBeDisabled()
    expect(screen.getByLabelText('settings:company.fields.currency')).toBeDisabled()
    expect(screen.getByText('settings:company.identity.immutableHint')).toBeInTheDocument()
  })

  it('locks fiscal identity fields for an editor who only has cosmetic settings permission', async () => {
    setTenant([], ['settings.update'])
    render(<CompanyPage />, { wrapper: wrapper() })

    expect(await screen.findByLabelText('settings:company.fields.name')).not.toBeDisabled()
    expect(screen.getByLabelText('settings:company.fields.legalName')).toBeDisabled()
    expect(screen.getByLabelText('settings:company.fields.taxId')).toBeDisabled()
    expect(screen.getByLabelText('settings:company.fields.registrationNumber')).toBeDisabled()
    expect(screen.getByText('settings:company.identity.fiscalPermissionHint')).toBeInTheDocument()
  })

  it('enables mutable fiscal identity fields for an admin', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })

    expect(await screen.findByLabelText('settings:company.fields.legalName')).not.toBeDisabled()
    expect(screen.getByLabelText('settings:company.fields.taxId')).not.toBeDisabled()
    expect(screen.getByLabelText('settings:company.fields.registrationNumber')).not.toBeDisabled()
  })

  it('requires confirmation before submitting a fiscal identity change', async () => {
    const user = userEvent.setup()
    render(<CompanyPage />, { wrapper: wrapper() })

    const legalName = await screen.findByLabelText('settings:company.fields.legalName')
    await user.type(legalName, 'Company A SARL')
    await user.click(screen.getByRole('button', { name: 'common:actions.save' }))

    expect(screen.getByText('settings:company.identity.confirmation.title')).toBeInTheDocument()
    expect(screen.getByText('settings:company.identity.confirmation.message')).toBeInTheDocument()
    expect(mockApiPatch).not.toHaveBeenCalled()

    await user.click(screen.getByTestId('confirm-dialog-confirm'))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith('/settings/company', expect.objectContaining({
        legal_name: 'Company A SARL',
      }))
    })
  })
})
