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
vi.mock('@/features/locations/hooks/useLocations', () => ({
  useLocations: () => ({ data: [] }),
}))

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

  // ── Import gate F1 — session expiry is the most reachable wrong-cause case.
  // `parseHeaders` carries a 120 s timeout (importApi.ts), so a session dying
  // mid-upload is routine, and api.ts is already redirecting to /login while
  // the toast fires. Telling that operator "contact your administrator" turns a
  // routine re-login into a support ticket.
  it.each([401, 419])('reports HTTP %i as a session expiry, not an infrastructure fault', async (status) => {
    mockParseHeaders.mockRejectedValue({
      isAxiosError: true,
      response: { status, data: { error: { code: 'UNAUTHENTICATED' } } },
      message: `Request failed with status code ${String(status)}`,
    })

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.sessionExpired')
    })
    expect(mockToastError).not.toHaveBeenCalledWith(`wizard.upload.serverError:${String(status)}`)
    expect(mockToastError).not.toHaveBeenCalledWith('wizard.upload.parseError')
  })

  // ── Import gate F2 — for a 413 the file IS the cause, so the generic
  // "your file is probably fine, try again" is affirmatively false and the
  // retry it advises is guaranteed to fail.
  it('reports HTTP 413 as a size problem rather than a generic server fault', async () => {
    mockParseHeaders.mockRejectedValue({
      isAxiosError: true,
      response: { status: 413, data: '' },
      message: 'Request failed with status code 413',
    })

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.tooLarge')
    })
    expect(mockToastError).not.toHaveBeenCalledWith('wizard.upload.serverError:413')
  })

  // ── Import gate F3 — the networkError branch must not depend on the current
  // strictness of `isApiError`. Today a no-response failure reaches us as a raw
  // AxiosError only because `isApiError` returns false and the interceptor's
  // `if (!response) reject(new Error('Network error'))` (api.ts) is unreachable.
  // Repair `isApiError` into a plain axios guard — a tempting one-line cleanup
  // in a file this lane must not touch — and that branch activates, every
  // network failure arrives as a bare Error, and BUG-004 silently returns with
  // a green suite. Recognise the sentinel so the taxonomy survives that change.
  it('recognises the interceptor\'s bare "Network error" sentinel as a network failure', async () => {
    mockParseHeaders.mockRejectedValue(new Error('Network error'))

    await chooseFile()

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('wizard.upload.networkError')
    })
    expect(mockToastError).not.toHaveBeenCalledWith('wizard.upload.parseError')
  })
})

/**
 * Import gate F7 — the suite above stubs `react-i18next` to echo keys, so a
 * typo'd key (`severError`) would pass green and ship a raw key to the
 * operator. There is no locale key-parity audit in `apps/web/tools/`. Assert
 * the taxonomy against the REAL catalogs instead, and assert the messages
 * DISCRIMINATE — a taxonomy whose branches resolve to the same sentence is the
 * masking bug wearing five names.
 */
describe('upload failure catalog (BUG-004 taxonomy)', () => {
  const TAXONOMY_KEYS = [
    'parseError',
    'serverError',
    'networkError',
    'sessionExpired',
    'tooLarge',
  ] as const

  it.each(['en', 'fr'])('defines every taxonomy key in %s with distinct copy', async (lang) => {
    const catalog = (await import(`../../../locales/${lang}/import.json`)) as {
      default: { wizard: { upload: Record<string, string> } }
    }
    const upload = catalog.default.wizard.upload

    for (const key of TAXONOMY_KEYS) {
      expect(upload[key], `${lang}: wizard.upload.${key} is missing`).toBeTruthy()
    }

    const messages = TAXONOMY_KEYS.map((key) => upload[key])
    expect(new Set(messages).size).toBe(TAXONOMY_KEYS.length)
  })

  it('does not tell the operator their file is probably fine on a generic server fault', async () => {
    const en = (await import('../../../locales/en/import.json')) as {
      default: { wizard: { upload: Record<string, string> } }
    }
    // The frontend cannot know the cause of an arbitrary 5xx, so `serverError`
    // must not assert one. (Import gate Q1: re-asserting a wrong cause is the
    // exact defect class BUG-004 exists to eliminate.)
    expect(en.default.wizard.upload['serverError']).not.toMatch(/probably fine/i)
    expect(en.default.wizard.upload['serverError']).toContain('{{status}}')
  })
})
