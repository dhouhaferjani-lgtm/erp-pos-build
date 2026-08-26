import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ImportWizardPage } from '../pages/ImportWizardPage'

/**
 * Campaign W4-1, gate r1 [CRITICAL] — `expiry_date` must be a MAPPABLE target on
 * the Products import.
 *
 * The server half of the lane works, but it is reached through
 * `ImportService::applyColumnMapping()`, which keeps ONLY mapped targets. The
 * wizard builds its mapping from `TARGET_COLUMNS`, so a column missing from that
 * list is neither auto-mapped nor selectable: it lands in `skippedColumns` and is
 * stripped from every row before validation. An operator downloads the official
 * template (which DOES carry the header), fills the expiry in, uploads it — and
 * every lot silently opens undated, with no error, no warning, and no mention in
 * the result workbook.
 *
 * The lane's API tests could not catch this: they post the file with NO
 * `column_mapping`, which is the `$mapping === null` early-return branch — the
 * one path no operator ever takes.
 *
 * ColumnMapper is deliberately NOT mocked here: the real component reading the
 * real TARGET_COLUMNS.products is the whole subject of this test.
 */

const mockParseHeaders = vi.hoisted(() => vi.fn())
const mockUpdateOptions = vi.hoisted(() => vi.fn())
const mockCreateMutate = vi.hoisted(() => vi.fn())
const mockSuggestMutate = vi.hoisted(() => vi.fn())
const mockRefetchPreview = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) => {
      if (key === 'mapping.skippedColumnsNotice') {
        return `skipped: ${String(options?.['columns'])}`
      }
      return key
    },
  }),
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn() },
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
  useCreateImport: () => ({ mutate: mockCreateMutate, isPending: false }),
  useExecuteImport: () => ({ mutate: vi.fn(), isSuccess: false }),
  useSuggestMapping: () => ({ mutate: mockSuggestMutate }),
  useImportJob: () => ({ data: undefined }),
  useImportErrors: () => ({ data: { data: [] } }),
  useImportPreview: () => ({
    data: {
      headers: [],
      rows: [],
      summary: { total_rows: 1, valid_rows: 1, invalid_rows: 0 },
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

vi.mock('../components/ValidationGrid', () => ({ ValidationGrid: () => null }))
vi.mock('../components/ImportProgress', () => ({ ImportProgress: () => null }))
vi.mock('../components/ImportPreviewTable', () => ({ ImportPreviewTable: () => null }))

function renderWizard() {
  return render(
    <MemoryRouter initialEntries={['/settings/import/products']}>
      <Routes>
        <Route path="/settings/import/:type" element={<ImportWizardPage />} />
      </Routes>
    </MemoryRouter>
  )
}

async function uploadAndReachMapping(): Promise<ReturnType<typeof userEvent.setup>> {
  const user = userEvent.setup()
  renderWizard()

  await user.click(screen.getByRole('button', { name: 'choose-file' }))
  await screen.findByText('wizard.upload.fileReady')
  await user.click(screen.getByRole('button', { name: 'common:actions.next' }))

  return user
}

describe('ImportWizardPage — the Products expiry_date column is mappable (W4-1)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockParseHeaders.mockResolvedValue({
      headers: ['name', 'quantity', 'location_code', 'purchase_price', 'expiry_date'],
      row_count: 1,
    })
    mockSuggestMutate.mockImplementation((
      _variables: unknown,
      options?: { onSuccess?: (data: { suggestions: Record<string, string> }) => void },
    ) => {
      // What MigrationWizardService::suggestColumnMapping returns for a sheet
      // whose headers already match the target names exactly.
      options?.onSuccess?.({
        suggestions: {
          name: 'name',
          quantity: 'quantity',
          location_code: 'location_code',
          purchase_price: 'purchase_price',
          expiry_date: 'expiry_date',
        },
      })
    })
    mockCreateMutate.mockImplementation((
      _variables: unknown,
      options?: { onSuccess?: (data: { data: { id: string } }) => void },
    ) => {
      options?.onSuccess?.({ data: { id: 'job-1' } })
    })
    mockUpdateOptions.mockResolvedValue({ data: { id: 'job-1' } })
    mockRefetchPreview.mockResolvedValue({ data: undefined })
  })

  it('auto-maps an expiry_date header instead of silently skipping it', async () => {
    await uploadAndReachMapping()

    // The row for the source column must report MAPPED. Skipped means the value
    // is stripped server-side and the lot opens undated with no feedback.
    expect(await screen.findByTestId('mapped-status-expiry_date')).toBeInTheDocument()
    expect(screen.queryByTestId('skipped-status-expiry_date')).not.toBeInTheDocument()
    expect(screen.queryByText(/skipped: .*expiry_date/)).not.toBeInTheDocument()
  })

  it('offers expiry_date as a selectable optional target for every other header', async () => {
    const { container } = renderWizard()
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'choose-file' }))
    await screen.findByText('wizard.upload.fileReady')
    await user.click(screen.getByRole('button', { name: 'common:actions.next' }))
    await screen.findByTestId('mapped-status-expiry_date')

    // Queried by VALUE, not accessible name: a target already claimed by another
    // row renders as "expiry_date (already mapped)", so the name differs per
    // select while the option value does not. Every one of the 5 source rows must
    // offer it, so an operator whose sheet calls the column "DLC" can point it here.
    const options = container.querySelectorAll('option[value="expiry_date"]')
    expect(options.length).toBe(5)
  })

  it('posts expiry_date in the column_mapping the wizard sends to the server', async () => {
    const user = await uploadAndReachMapping()

    await screen.findByTestId('mapped-status-expiry_date')
    await user.click(screen.getByRole('button', { name: 'wizard.mapping.validate' }))

    await waitFor(() => {
      expect(mockCreateMutate).toHaveBeenCalled()
    })

    const payload = mockCreateMutate.mock.calls[0]?.[0] as { columnMapping?: Record<string, string> }
    expect(payload.columnMapping).toMatchObject({ expiry_date: 'expiry_date' })
  })
})
