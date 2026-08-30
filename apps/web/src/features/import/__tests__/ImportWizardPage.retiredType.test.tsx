import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { ImportWizardPage } from '../pages/ImportWizardPage'
import { DEPRECATED_IMPORT_TYPES, isDeprecatedImportType } from '../types'

/**
 * Owner ruling D4 (document-per-action remediation, lane V6): the
 * `stock_levels` import is retired — it set stock quantities directly, with no
 * stock movement and no justifying document. The dashboard never links to it,
 * but `/settings/import/stock_levels` is still a live route (bookmarks, old
 * docs), so the wizard must explain the retirement instead of rendering an
 * upload form the API now refuses with a 422.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))
vi.mock('@/lib/api', () => ({ authenticatedDownload: vi.fn() }))
vi.mock('@/features/locations/hooks/useScopedLocations', () => ({
  useScopedLocations: () => ({ data: [] }),
}))

vi.mock('../api/importApi', () => ({
  importApi: {
    parseHeaders: vi.fn(),
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
  FileUpload: () => <button type="button">choose-file</button>,
}))
vi.mock('../components/ColumnMapper', () => ({ ColumnMapper: () => null }))
vi.mock('../components/ValidationGrid', () => ({ ValidationGrid: () => null }))
vi.mock('../components/ImportProgress', () => ({ ImportProgress: () => null }))
vi.mock('../components/ImportPreviewTable', () => ({ ImportPreviewTable: () => null }))

function renderWizardFor(type: string) {
  return render(
    <MemoryRouter initialEntries={[`/settings/import/${type}`]}>
      <Routes>
        <Route path="/settings/import/:type" element={<ImportWizardPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('ImportWizardPage retired import types (D4)', () => {
  it('flags stock_levels as retired', () => {
    expect(DEPRECATED_IMPORT_TYPES).toContain('stock_levels')
    expect(isDeprecatedImportType('stock_levels')).toBe(true)
    expect(isDeprecatedImportType('products')).toBe(false)
  })

  it('explains the retirement instead of rendering the upload step', () => {
    renderWizardFor('stock_levels')

    expect(screen.getByText('wizard.retired.title')).toBeInTheDocument()
    expect(screen.getByText('wizard.retired.description')).toBeInTheDocument()

    // No upload affordance at all — the API would refuse the request.
    expect(screen.queryByRole('button', { name: 'choose-file' })).not.toBeInTheDocument()
    expect(screen.queryByText('wizard.upload.title')).not.toBeInTheDocument()
  })

  it('routes the operator to the compliant Products import', () => {
    renderWizardFor('stock_levels')

    const link = screen.getByRole('link', { name: 'wizard.retired.goToProducts' })
    expect(link).toHaveAttribute('href', '/settings/import/products')
  })

  it('still renders the normal wizard for live types', () => {
    renderWizardFor('products')

    expect(screen.queryByText('wizard.retired.title')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'choose-file' })).toBeInTheDocument()
  })
})
