import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios'
import { TemplateEditorPage } from '../pages/TemplateEditorPage'
import * as countryDefaultsApi from '../api/countryDefaultsApi'
import enCountryDefaults from '@/locales/en/adminCountryDefaults.json'
import frCountryDefaults from '@/locales/fr/adminCountryDefaults.json'
import type { CountryDefaultTemplate, SystemAccountPurpose } from '../types'
import { adminApi } from '@/features/admin/lib/adminApi'

vi.mock('../api/countryDefaultsApi', () => ({
  getTemplate: vi.fn(),
  publishTemplate: vi.fn(),
  saveTemplateRows: vi.fn(),
  validateTemplate: vi.fn(),
}))

const template = {
  id: 'template-1',
  domain: 'chart_of_accounts' as const,
  name: 'PCN Tunisie draft',
  description: null,
  status: 'draft' as const,
  content_hash: null,
  standard_ref: null,
  certified_country_codes: null,
  capability_registry_version: null,
  certified_by: null,
  published_at: null,
  cloned_from_id: null,
  created_by: 'admin-1',
  created_at: '2026-08-11T10:00:00Z',
  updated_at: '2026-08-11T10:00:00Z',
  rows: [
    {
      id: 'row-1',
      code: '5312',
      name: 'Caisse',
      type: 'asset' as const,
      parent_code: null,
      system_purpose: 'cash' as const,
      is_system: true,
      sort_order: 0,
      is_protected: true,
      protection_source: 'treasury_instrument_literal',
    },
    {
      id: 'row-2',
      code: '701',
      name: 'Sales',
      type: 'revenue' as const,
      parent_code: null,
      system_purpose: 'product_revenue' as const,
      is_system: true,
      sort_order: 1,
      is_protected: false,
      protection_source: null,
    },
    {
      id: 'row-3',
      code: '702',
      name: 'Other sales',
      type: 'revenue' as const,
      parent_code: null,
      system_purpose: null,
      is_system: false,
      sort_order: 2,
      is_protected: false,
      protection_source: null,
    },
  ],
  account_types: ['asset', 'liability', 'equity', 'revenue', 'expense'] as const,
  system_account_purposes: ['cash', 'product_revenue', 'supplier_payable'] as const,
} satisfies CountryDefaultTemplate

function apiError(status: number): AxiosError {
  const response: AxiosResponse = {
    status,
    statusText: String(status),
    data: {},
    headers: {},
    config: { headers: new AxiosHeaders() },
  }
  return new AxiosError(`Request failed with status code ${status}`, undefined, undefined, undefined, response)
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <MemoryRouter initialEntries={['/admin/country-defaults/templates/template-1']}>
      <QueryClientProvider client={queryClient}>
        <Routes>
          <Route path="/admin/country-defaults/templates/:templateId" element={<TemplateEditorPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>
  )
}

describe('TemplateEditorPage', () => {
  beforeEach(() => {
    vi.mocked(countryDefaultsApi.getTemplate).mockResolvedValue(template)
    vi.mocked(countryDefaultsApi.validateTemplate).mockResolvedValue({
      valid: false,
      scope: ['TN'],
      errors: [{ code: 'missing_required_purpose', parameters: { purpose: 'supplier_payable' } }],
    })
  })

  it('locks protected rows with an explanation while ordinary rows remain editable', async () => {
    renderPage()

    const lockedCode = await screen.findByDisplayValue('5312')
    expect(lockedCode).toBeDisabled()
    expect(lockedCode).toHaveAttribute('aria-describedby', 'protected-row-1')
    expect(screen.getByText('Treasury resolves this account by literal code.')).toHaveAttribute('id', 'protected-row-1')
    expect(screen.getByDisplayValue('701')).toBeEnabled()
  })

  it('keeps validation visible and blocks saving invalid grid rows', async () => {
    const user = userEvent.setup()
    renderPage()

    const editableCode = await screen.findByDisplayValue('701')
    await user.clear(editableCode)
    await user.click(screen.getByRole('button', { name: 'Save rows' }))

    expect(screen.getByText('Account code is required.')).toBeInTheDocument()
    expect(screen.getByRole('complementary', { name: 'Validation' })).toBeInTheDocument()
    expect(screen.getByText('Required purpose Supplier payable (AP) is missing.')).toBeInTheDocument()
    expect(countryDefaultsApi.saveTemplateRows).not.toHaveBeenCalled()
  })

  it('publishes with a standard and jurisdiction scope, then displays the returned hash', async () => {
    const user = userEvent.setup()
    vi.mocked(countryDefaultsApi.publishTemplate).mockResolvedValue({
      ...template,
      status: 'published',
      standard_ref: 'NC 41-2026',
      certified_country_codes: ['TN'],
      content_hash: 'sha256-certified',
    })
    renderPage()

    await screen.findByDisplayValue('5312')
    await user.click(screen.getByRole('button', { name: 'Publish' }))
    await user.type(screen.getByLabelText('Accounting standard'), 'NC 41-2026')
    await user.type(screen.getByLabelText('Certified jurisdictions'), 'TN')
    await user.click(screen.getByRole('button', { name: 'Confirm publish' }))

    expect(countryDefaultsApi.publishTemplate).toHaveBeenCalledWith('template-1', {
      standard_ref: 'NC 41-2026',
      certified_country_codes: ['TN'],
    })
    expect(await screen.findByText('sha256-certified')).toBeInTheDocument()
  })

  it('omits an empty validation scope and normalizes human comma spacing at the API boundary', async () => {
    const get = vi.spyOn(adminApi, 'get').mockResolvedValue({
      data: { data: { valid: true, scope: [], errors: [] } },
    })
    const actualApi = await vi.importActual<typeof import('../api/countryDefaultsApi')>('../api/countryDefaultsApi')

    await actualApi.validateTemplate('template-1', '   ')
    expect(get).toHaveBeenLastCalledWith(
      '/admin/country-defaults/templates/template-1/validation',
      { params: undefined },
    )

    await actualApi.validateTemplate('template-1', ' TN,  FR ')
    expect(get).toHaveBeenLastCalledWith(
      '/admin/country-defaults/templates/template-1/validation',
      { params: { scope: 'TN,FR' } },
    )
    get.mockRestore()
  })

  it('distinguishes validation transport errors from an invalid template', async () => {
    vi.mocked(countryDefaultsApi.validateTemplate).mockRejectedValue(apiError(500))
    renderPage()

    expect(await screen.findByText('Validation is temporarily unavailable. Try again.')).toBeInTheDocument()
    expect(screen.queryByText('Changes required')).not.toBeInTheDocument()
  })

  it('explains timbre and wildcard scope rules when validation rejects TN,FR', async () => {
    const user = userEvent.setup()
    vi.mocked(countryDefaultsApi.validateTemplate)
      .mockResolvedValueOnce({ valid: false, scope: [], errors: [] })
      .mockRejectedValueOnce(apiError(422))
    renderPage()

    await screen.findByDisplayValue('5312')
    await user.click(screen.getByRole('button', { name: 'Publish' }))
    await user.type(screen.getByLabelText('Certified jurisdictions'), 'TN,FR')

    expect(await screen.findByText('Exact scopes cannot mix countries that require fiscal timbre accounts with countries that do not. The wildcard (*) must be used alone.')).toBeInTheDocument()
    expect(screen.queryByText('Validation is temporarily unavailable. Try again.')).not.toBeInTheDocument()
  })

  it('debounces complete scopes and never validates incomplete scope prefixes', async () => {
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => { expect(countryDefaultsApi.validateTemplate).toHaveBeenCalledWith('template-1', '') })
    await screen.findByDisplayValue('5312')
    vi.mocked(countryDefaultsApi.validateTemplate).mockClear()
    await user.click(screen.getByRole('button', { name: 'Publish' }))
    const scope = screen.getByLabelText('Certified jurisdictions')

    await user.type(scope, 'T')
    await new Promise((resolve) => { setTimeout(resolve, 400) })
    expect(countryDefaultsApi.validateTemplate).not.toHaveBeenCalled()
    expect(screen.queryByText('Validation is temporarily unavailable. Try again.')).not.toBeInTheDocument()

    await user.type(scope, 'N')
    expect(countryDefaultsApi.validateTemplate).not.toHaveBeenCalled()
    await waitFor(() => { expect(countryDefaultsApi.validateTemplate).toHaveBeenCalledTimes(1) })
    expect(countryDefaultsApi.validateTemplate).toHaveBeenCalledWith('template-1', 'TN')
  })

  it('keeps an incomplete-scope hint visible after the publish dialog closes', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByDisplayValue('5312')
    await user.click(screen.getByRole('button', { name: 'Publish' }))
    await user.type(screen.getByLabelText('Certified jurisdictions'), 'T')
    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.getByText('Finish entering two-letter country codes, or clear the scope to validate the whole template.')).toBeInTheDocument()
    expect(countryDefaultsApi.validateTemplate).not.toHaveBeenCalledWith('template-1', 'T')
  })

  it('keeps publish errors handled and visible inside the modal', async () => {
    const user = userEvent.setup()
    vi.mocked(countryDefaultsApi.publishTemplate).mockRejectedValue(apiError(422))
    renderPage()

    await screen.findByDisplayValue('5312')
    await user.click(screen.getByRole('button', { name: 'Publish' }))
    await user.type(screen.getByLabelText('Accounting standard'), 'NC 41-2026')
    await user.type(screen.getByLabelText('Certified jurisdictions'), 'TN')
    await user.click(screen.getByRole('button', { name: 'Confirm publish' }))

    expect(await screen.findByText('Review the submitted values and certification rules.')).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Certify and publish' })).toBeInTheDocument()
  })

  it('adds and saves a collision-safe new row without sending a fabricated backend id', async () => {
    const user = userEvent.setup()
    vi.mocked(countryDefaultsApi.saveTemplateRows).mockResolvedValue(template)
    renderPage()

    await screen.findByDisplayValue('5312')
    await user.click(screen.getByRole('button', { name: 'Delete account 701' }))
    await user.click(screen.getByRole('button', { name: 'Add account' }))
    const newRow = screen.getAllByRole('row').at(-1)
    expect(newRow).toBeDefined()
    if (newRow === undefined) throw new Error('Expected the newly added account row.')
    const fields = within(newRow).getAllByRole('textbox')
    await user.type(fields[0], '703')
    await user.type(fields[1], 'New revenue')
    await user.click(screen.getByRole('button', { name: 'Save rows' }))

    expect(countryDefaultsApi.saveTemplateRows).toHaveBeenCalledTimes(1)
    const submitted = vi.mocked(countryDefaultsApi.saveTemplateRows).mock.calls[0][1]
    expect(submitted.at(-1)).toMatchObject({ code: '703', name: 'New revenue', sort_order: 3 })
    expect(submitted.at(-1)).not.toHaveProperty('id')
  })

  it('gives blank rows distinct accessible identities and omits blank parent options', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByDisplayValue('5312')
    await user.click(screen.getByRole('button', { name: 'Add account' }))
    await user.click(screen.getByRole('button', { name: 'Add account' }))

    expect(screen.getByRole('textbox', { name: 'Account code New account 4' })).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Account code New account 5' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete account New account 4' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete account New account 5' })).toBeInTheDocument()
    for (const row of screen.getAllByRole('row').slice(1)) {
      const parent = within(row).getAllByRole('combobox')[1]
      expect(within(parent).getAllByRole('option', { name: 'None' })).toHaveLength(1)
    }
  })

  it('clears a row validation error as soon as that row is edited', async () => {
    const user = userEvent.setup()
    renderPage()

    const editableCode = await screen.findByDisplayValue('701')
    await user.clear(editableCode)
    await user.click(screen.getByRole('button', { name: 'Save rows' }))
    expect(screen.getByText('Account code is required.')).toBeInTheDocument()

    await user.type(editableCode, '701')
    expect(screen.queryByText('Account code is required.')).not.toBeInTheDocument()
  })

  it('clears a deleted row error before a row identity is reused', async () => {
    const user = userEvent.setup()
    const uuid = '00000000-0000-4000-8000-000000000001'
    const randomUuid = vi.spyOn(crypto, 'randomUUID').mockReturnValue(uuid)
    renderPage()

    await screen.findByDisplayValue('5312')
    await user.click(screen.getByRole('button', { name: 'Add account' }))
    await user.click(screen.getByRole('button', { name: 'Save rows' }))
    expect(screen.getByText('Account code is required.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Delete account New account 4' }))
    await user.click(screen.getByRole('button', { name: 'Add account' }))

    expect(screen.queryByText('Account code is required.')).not.toBeInTheDocument()
    randomUuid.mockRestore()
  })

  it('does not offer a metadata save action when metadata is not editable', async () => {
    renderPage()

    await screen.findByDisplayValue('5312')
    expect(screen.queryByRole('button', { name: 'Save details' })).not.toBeInTheDocument()
  })

  it('has exhaustive generated purpose labels in English and French', () => {
    const enLabels = enCountryDefaults.purposes satisfies Record<SystemAccountPurpose, string>
    const frLabels = frCountryDefaults.purposes satisfies Record<SystemAccountPurpose, string>
    expect(Object.keys(enLabels)).toHaveLength(41)
    expect(Object.keys(frLabels).sort()).toEqual(Object.keys(enLabels).sort())
    expect(Object.values(enLabels)).not.toContain('supplier_payable')
    expect(Object.values(frLabels)).not.toContain('supplier_payable')
  })

  it('refetches server-normalized rows and releases local edits after save', async () => {
    const user = userEvent.setup()
    const normalized = {
      ...template,
      rows: template.rows.map((row) => row.id === 'row-2' ? { ...row, name: 'Server normalized sales' } : row),
    }
    vi.mocked(countryDefaultsApi.getTemplate)
      .mockResolvedValueOnce(template)
      .mockResolvedValue(normalized)
    vi.mocked(countryDefaultsApi.getTemplate).mockClear()
    vi.mocked(countryDefaultsApi.saveTemplateRows).mockResolvedValue(normalized)
    renderPage()

    const name = await screen.findByDisplayValue('Sales')
    await user.clear(name)
    await user.type(name, 'Local sales')
    await user.click(screen.getByRole('button', { name: 'Save rows' }))

    expect(await screen.findByDisplayValue('Server normalized sales')).toBeInTheDocument()
    expect(screen.queryByDisplayValue('Local sales')).not.toBeInTheDocument()
    expect(countryDefaultsApi.getTemplate).toHaveBeenCalledTimes(2)
  })

  it('uses a localized fallback for an unknown protection source', async () => {
    vi.mocked(countryDefaultsApi.getTemplate).mockResolvedValue({
      ...template,
      rows: [{ ...template.rows[0], protection_source: 'future_protection_source' }],
    })
    renderPage()

    expect(await screen.findByText('This account is protected by a platform dependency.')).toBeInTheDocument()
    expect(screen.queryByText('protection.future_protection_source')).not.toBeInTheDocument()
  })
})
