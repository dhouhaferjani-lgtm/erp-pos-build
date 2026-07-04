import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ImportWizardPage } from '../pages/ImportWizardPage'

let nextMapping: Record<string, string> = {}

const mockParseHeaders = vi.hoisted(() => vi.fn())
const mockUpdateOptions = vi.hoisted(() => vi.fn())
const mockCreateMutate = vi.hoisted(() => vi.fn())
const mockSuggestMutate = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('@/lib/api', () => ({
  authenticatedDownload: vi.fn(),
}))

vi.mock('../api/importApi', () => ({
  importApi: {
    parseHeaders: mockParseHeaders,
    updateOptions: mockUpdateOptions,
    downloadTemplateUrl: (type: string) => `/migration-wizard/template/${type}`,
  },
}))

vi.mock('../api/queries', () => ({
  useCreateImport: () => ({
    mutate: mockCreateMutate,
    isPending: false,
  }),
  useExecuteImport: () => ({
    mutate: vi.fn(),
    isSuccess: false,
  }),
  useSuggestMapping: () => ({
    mutate: mockSuggestMutate,
  }),
  useImportJob: () => ({ data: undefined }),
  useImportErrors: () => ({ data: { data: [] } }),
  useImportPreview: () => ({
    data: undefined,
    isLoading: false,
    isError: false,
  }),
}))

vi.mock('../components', () => ({
  FileUpload: ({ onFileSelect }: { onFileSelect: (file: File) => Promise<void> }) => (
    <button
      type="button"
      onClick={() => onFileSelect(new File(['name'], 'products.csv', { type: 'text/csv' }))}
    >
      choose-file
    </button>
  ),
  ColumnMapper: ({ onMappingChange }: { onMappingChange: (mapping: Record<string, string>) => void }) => (
    <button type="button" onClick={() => onMappingChange(nextMapping)}>
      apply-mapping
    </button>
  ),
  ValidationGrid: () => null,
  ImportProgress: () => null,
  ImportPreviewTable: () => null,
}))

function renderWizard() {
  return render(
    <MemoryRouter initialEntries={['/settings/import/products']}>
      <Routes>
        <Route path="/settings/import/:type" element={<ImportWizardPage />} />
      </Routes>
    </MemoryRouter>
  )
}

async function uploadAndMap() {
  const user = userEvent.setup()

  renderWizard()

  await user.click(screen.getByRole('button', { name: 'choose-file' }))
  await screen.findByText('wizard.upload.fileReady')
  await user.click(screen.getByRole('button', { name: 'common:actions.next' }))
  await user.click(screen.getByRole('button', { name: 'apply-mapping' }))
  await user.click(screen.getByRole('button', { name: 'wizard.mapping.validate' }))
}

describe('ImportWizardPage product options step', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    nextMapping = {}
    mockParseHeaders.mockResolvedValue({
      headers: ['name', 'price_ttc', 'price_ht', 'margin'],
      row_count: 1,
    })
    mockSuggestMutate.mockImplementation((_variables, options) => {
      options?.onSuccess?.({ suggestions: {} })
    })
    mockCreateMutate.mockImplementation((_variables, options) => {
      options?.onSuccess?.({ data: { id: 'job-1' } })
    })
    mockUpdateOptions.mockResolvedValue({ data: { id: 'job-1' } })
  })

  it('shows the options step when at least two product price columns are mapped', async () => {
    nextMapping = {
      name: 'name',
      price_ttc: 'sale_price_incl_tax',
      price_ht: 'sale_price_excl_tax',
    }

    await uploadAndMap()

    expect(await screen.findByRole('heading', { name: 'options.priceAuthorityTitle' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'options.priceAuthority.ttc' })).toBeChecked()
  })

  it('skips the options step when fewer than two product price columns are mapped', async () => {
    nextMapping = {
      name: 'name',
      price_ttc: 'sale_price_incl_tax',
    }

    await uploadAndMap()

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'wizard.validation.title' })).toBeInTheDocument()
    })
    expect(screen.queryByRole('heading', { name: 'options.priceAuthorityTitle' })).not.toBeInTheDocument()
  })
})
