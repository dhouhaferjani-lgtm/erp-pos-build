import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ImportWizardPage } from '../pages/ImportWizardPage'

/**
 * BUG-004 — the wizard wrapped `importApi.parseHeaders(file)` in a bare
 * `catch {}` and mapped EVERY failure to `wizard.upload.parseError` ("invalid
 * file"). A 500 from the API, an nginx 413, a CSRF bounce and a dropped
 * connection all told the operator their file was bad. That masking is what
 * cost days of diagnosis on BUG-001 (nginx 500 on uploads > 128 KB) and
 * BUG-002 (Windows-1252 CSV → 500).
 *
 * Only a real 422 from `MigrationWizardController::parseHeaders` means "this
 * file cannot be parsed".
 */

const mockParseHeaders = vi.hoisted(() => vi.fn())
const mockToastError = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, options?: Record<string, unknown>) => (
    options && 'status' in options ? `${key}:${String(options['status'])}` : key
  ) }),
}))

vi.mock('sonner', () => ({
  toast: { error: mockToastError, success: vi.fn() },
}))

vi.mock('@/lib/api', () => ({ authenticatedDownload: vi.fn() }))

vi.mock('../api/importApi', () => ({
  importApi: {
    parseHeaders: mockParseHeaders,
    updateOptions: vi.fn(),
    downloadTemplateUrl: (type: string) => `/migration-wizard/template/${type}`,
  },
}))

vi.mock('../api/queries', () => ({
  useCreateImport: () => ({ mutate: vi.fn(), isPending: false }),
  useExecuteImport: () => ({ mutate: vi.fn(), isSuccess: false }),
  useSuggestMapping: () => ({ mutate: vi.fn() }),
  useImportJob: () => ({ data: undefined }),
  useImportErrors: () => ({ data: { data: [] } }),
  useImportPreview: () => ({
    data: undefined,
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
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

vi.mock('../components/ColumnMapper', () => ({ ColumnMapper: () => null }))
vi.mock('../components/ValidationGrid', () => ({ ValidationGrid: () => null }))
vi.mock('../components/ImportProgress', () => ({ ImportProgress: () => null }))
vi.mock('../components/ImportPreviewTable', () => ({ ImportPreviewTable: () => null }))

async function chooseFile() {
  const user = userEvent.setup()
  render(
    <MemoryRouter initialEntries={['/settings/import/products']}>
      <Routes>
        <Route path="/settings/import/:type" element={<ImportWizardPage />} />
      </Routes>
    </MemoryRouter>,
  )
  await user.click(screen.getByRole('button', { name: 'choose-file' }))
}

describe('ImportWizardPage upload error reporting (BUG-004)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('reports a server failure with its HTTP status, not "invalid file"', async () => {
    mockParseHeaders.mockRejectedValue({
      isAxiosError: true,
      response: { status: 500, data: 'nginx html error page' },
      message: 'Request failed with status code 500',
    })

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.serverError:500')
    })
    expect(mockToastError).not.toHaveBeenCalledWith('wizard.upload.parseError')
  })

  it('reports an nginx 413 with its status rather than blaming the file', async () => {
    mockParseHeaders.mockRejectedValue({
      isAxiosError: true,
      response: { status: 413, data: '' },
      message: 'Request failed with status code 413',
    })

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.serverError:413')
    })
    expect(mockToastError).not.toHaveBeenCalledWith('wizard.upload.parseError')
  })

  it('reports a network failure (no response) distinctly', async () => {
    mockParseHeaders.mockRejectedValue({
      isAxiosError: true,
      code: 'ERR_NETWORK',
      message: 'Network Error',
    })

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.networkError')
    })
    expect(mockToastError).not.toHaveBeenCalledWith('wizard.upload.parseError')
  })

  it('still reports a genuine 422 parse rejection as an invalid file', async () => {
    mockParseHeaders.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { error: 'Could not parse file. Please upload a valid CSV or Excel file.' },
      },
      message: 'Request failed with status code 422',
    })

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.parseError')
    })
  })

  it('falls back to the parse error for a non-HTTP throw', async () => {
    mockParseHeaders.mockRejectedValue(new TypeError('headers is not iterable'))

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.parseError')
    })
  })
})
