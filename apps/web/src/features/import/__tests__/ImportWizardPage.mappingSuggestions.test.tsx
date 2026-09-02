import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'
import { ImportWizardPage } from '../pages/ImportWizardPage'

const mockParseHeaders = vi.hoisted(() => vi.fn())
const mockCreateMutate = vi.hoisted(() => vi.fn())
const mockSuggestMappingApi = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn() },
}))

vi.mock('@/lib/api', () => ({ authenticatedDownload: vi.fn() }))
vi.mock('@/features/locations/hooks/useScopedLocations', () => ({
  useScopedLocations: () => ({ data: [] }),
}))

vi.mock('../api/importApi', () => ({
  importApi: {
    parseHeaders: mockParseHeaders,
    suggestMapping: mockSuggestMappingApi,
    updateOptions: vi.fn(),
    downloadTemplateUrl: (type: string) => `/migration-wizard/template/${type}`,
  },
}))

vi.mock('../api/queries', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/queries')>()

  return {
    ...actual,
    useCreateImport: () => ({ mutate: mockCreateMutate, isPending: false }),
    useExecuteImport: () => ({ mutate: vi.fn(), isSuccess: false }),
    useImportJob: () => ({ data: undefined }),
    useImportErrors: () => ({ data: { data: [] } }),
    useImportPreview: () => ({
      data: undefined,
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    }),
  }
})

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

vi.mock('../components/ValidationGrid', () => ({ ValidationGrid: () => null }))
vi.mock('../components/ImportProgress', () => ({ ImportProgress: () => null }))
vi.mock('../components/ImportPreviewTable', () => ({ ImportPreviewTable: () => null }))

function renderWizard() {
  return renderWithProviders(
    <Routes>
      <Route path="/settings/import/:type" element={<ImportWizardPage />} />
    </Routes>,
    { route: '/settings/import/products' },
  )
}

describe('ImportWizardPage mapping suggestions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockParseHeaders.mockResolvedValue({ headers: ['name'], row_count: 1 })
    mockSuggestMappingApi.mockRejectedValue(new Error('suggestion service unavailable'))
  })

  it('surfaces an automatic-matching failure and still accepts a manual mapping', async () => {
    const user = userEvent.setup()
    renderWizard()

    await user.click(screen.getByRole('button', { name: 'choose-file' }))
    await screen.findByText('wizard.upload.fileReady')
    await user.click(screen.getByRole('button', { name: 'common:actions.next' }))

    expect(await screen.findByRole('status')).toHaveTextContent('mapping.automaticMatchingFailed')

    await user.selectOptions(screen.getByRole('combobox'), 'name')
    await user.click(screen.getByRole('button', { name: 'wizard.mapping.validate' }))

    await waitFor(() => {
      expect(mockCreateMutate).toHaveBeenCalledWith(
        expect.objectContaining({ columnMapping: { name: 'name' } }),
        expect.any(Object),
      )
    })
  })

  it('applies fresh suggestions on a second upload when the mapping is untouched by the user', async () => {
    const user = userEvent.setup()
    mockParseHeaders.mockResolvedValue({ headers: ['name', 'sku'], row_count: 1 })
    mockSuggestMappingApi
      .mockResolvedValueOnce({
        suggestions: { name: 'name', sku: null },
        unmapped_source: ['sku'],
        unmapped_target: ['sku'],
      })
      .mockResolvedValueOnce({
        suggestions: { name: 'name', sku: 'sku' },
        unmapped_source: [],
        unmapped_target: [],
      })
    renderWizard()

    await user.click(screen.getByRole('button', { name: 'choose-file' }))
    await waitFor(() => {
      expect(mockSuggestMappingApi).toHaveBeenCalledTimes(1)
    })
    await user.click(screen.getByRole('button', { name: 'common:actions.next' }))
    const firstMapping = screen.getAllByRole('combobox')
    expect(firstMapping[0]).toHaveValue('name')
    expect(firstMapping[1]).toHaveValue('')

    await user.click(screen.getByRole('button', { name: 'common:actions.back' }))
    await user.click(screen.getByRole('button', { name: 'choose-file' }))
    await waitFor(() => {
      expect(mockSuggestMappingApi).toHaveBeenCalledTimes(2)
    })
    await user.click(screen.getByRole('button', { name: 'common:actions.next' }))

    await waitFor(() => {
      const secondMapping = screen.getAllByRole('combobox')
      expect(secondMapping[0]).toHaveValue('name')
      expect(secondMapping[1]).toHaveValue('sku')
    })
  })
})
