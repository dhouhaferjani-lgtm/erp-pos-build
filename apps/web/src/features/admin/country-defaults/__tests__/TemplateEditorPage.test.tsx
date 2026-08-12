import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TemplateEditorPage } from '../pages/TemplateEditorPage'
import * as countryDefaultsApi from '../api/countryDefaultsApi'
import enCountryDefaults from '@/locales/en/adminCountryDefaults.json'
import frCountryDefaults from '@/locales/fr/adminCountryDefaults.json'
import type { CountryDefaultTemplate, SystemAccountPurpose } from '../types'

vi.mock('../api/countryDefaultsApi', () => ({
  getTemplate: vi.fn(),
  publishTemplate: vi.fn(),
  saveTemplateRows: vi.fn(),
  updateTemplate: vi.fn(),
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

  it('has exhaustive generated purpose labels in English and French', () => {
    const enLabels = enCountryDefaults.purposes satisfies Record<SystemAccountPurpose, string>
    const frLabels = frCountryDefaults.purposes satisfies Record<SystemAccountPurpose, string>
    expect(Object.keys(enLabels)).toHaveLength(41)
    expect(Object.keys(frLabels).sort()).toEqual(Object.keys(enLabels).sort())
    expect(Object.values(enLabels)).not.toContain('supplier_payable')
    expect(Object.values(frLabels)).not.toContain('supplier_payable')
  })
})
