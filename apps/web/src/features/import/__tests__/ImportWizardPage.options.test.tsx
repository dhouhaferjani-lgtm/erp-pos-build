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
const mockRefetchPreview = vi.hoisted(() => vi.fn())

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
    data: {
      headers: [],
      rows: [],
      summary: { total_rows: 1, valid_rows: 0, invalid_rows: 1 },
      placement: { max_depth: 3, nodes_to_create: [], placements_to_set: [] },
    },
    isLoading: false,
    isError: false,
    refetch: mockRefetchPreview,
  }),
}))

vi.mock('../components/FileUpload', () => ({
  FileUpload: ({ onFileSelect }: { onFileSelect: (file: File) => Promise<void> }) => (
    <button
      type="button"
      onClick={() => { void onFileSelect(new File(['name'], 'products.csv', { type: 'text/csv' })) }}
    >
      choose-file
    </button>
  ),
}))

vi.mock('../components/ColumnMapper', () => ({
  ColumnMapper: ({ onMappingChange }: { onMappingChange: (mapping: Record<string, string>) => void }) => (
    <button type="button" onClick={() => { onMappingChange(nextMapping) }}>
      apply-mapping
    </button>
  ),
}))

vi.mock('../components/ValidationGrid', () => ({
  ValidationGrid: () => null,
}))

vi.mock('../components/ImportProgress', () => ({
  ImportProgress: () => null,
}))

vi.mock('../components/ImportPreviewTable', () => ({
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
    mockSuggestMutate.mockImplementation((_variables: unknown, options?: { onSuccess?: (data: { suggestions: Record<string, string> }) => void }) => {
      options?.onSuccess?.({ suggestions: {} })
    })
    mockCreateMutate.mockImplementation((_variables: unknown, options?: { onSuccess?: (data: { data: { id: string } }) => void }) => {
      options?.onSuccess?.({ data: { id: 'job-1' } })
    })
    mockUpdateOptions.mockResolvedValue({ data: { id: 'job-1' } })
    mockRefetchPreview.mockResolvedValue({ data: undefined })
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

  it('configures strict or auto-create placement planning when placement_path is mapped', async () => {
    const user = userEvent.setup()
    nextMapping = {
      name: 'name',
      placement: 'placement_path',
    }

    await uploadAndMap()

    expect(await screen.findByRole('heading', { name: 'options.placementTitle' })).toBeInTheDocument()
    const mode = screen.getByLabelText('options.placementMode')
    await user.selectOptions(mode, 'auto_create')
    expect(screen.getAllByLabelText(/options\.placementDepth/)).toHaveLength(3)
    await user.click(screen.getByRole('button', { name: 'common:actions.next' }))

    await waitFor(() => {
      expect(mockUpdateOptions).toHaveBeenCalledWith('job-1', expect.objectContaining({
        placement_mode: 'auto_create',
        placement_node_types: ['aisle', 'rack', 'shelf'],
      }))
    })
    expect(mockRefetchPreview).toHaveBeenCalledOnce()
  })
})
