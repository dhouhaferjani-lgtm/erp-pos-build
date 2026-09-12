import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import realProductsPreviewResponse from '../__fixtures__/real-products-preview-response.json'
import { ImportWizardPage } from '../pages/ImportWizardPage'
import type { ImportJob, ImportPreview, ImportResult } from '../types'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useImportProgressStore } from '@/stores/importProgressStore'

const mockParseHeaders = vi.hoisted(() => vi.fn())
const mockUpdateOptions = vi.hoisted(() => vi.fn())
const mockCreateMutate = vi.hoisted(() => vi.fn())
const mockExecuteMutate = vi.hoisted(() => vi.fn())
const mockDeleteJob = vi.hoisted(() => vi.fn())
const mockPreview = vi.hoisted(() => ({
  current: {
    headers: ['name'], rows: [],
    summary: { total_rows: 7, valid_rows: 7, invalid_rows: 0 },
    error_summary: { unknown_units: [] },
    duplicates: {
      counts: { new: 1, existing_sku: 2, existing_barcode: 1, existing_name: 1, in_file: 1, refused: 1 },
      matched_by_name: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
      refused: [{ row_number: 7, code: 'sku_held_by_deleted_product' }],
    },
  } as ImportPreview,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { codes?: string }) => options?.codes ? `${key} ${options.codes}` : key,
  }),
}))
vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))
vi.mock('@/lib/api', () => ({ authenticatedDownload: vi.fn(), apiGet: vi.fn().mockResolvedValue([]) }))
vi.mock('../api/importApi', () => ({
  importApi: {
    parseHeaders: mockParseHeaders,
    updateOptions: mockUpdateOptions,
    deleteJob: mockDeleteJob,
    downloadTemplateUrl: (type: string) => `/migration-wizard/template/${type}`,
  },
}))
vi.mock('../api/queries', () => ({
  useCreateImport: () => ({ mutate: mockCreateMutate, isPending: false }),
  useExecuteImport: () => ({ mutate: mockExecuteMutate, isSuccess: false, isPending: false }),
  useSuggestMapping: () => ({
    mutate: (_variables: unknown, options?: { onSuccess?: (data: { suggestions: Record<string, string> }) => void }) => {
      options?.onSuccess?.({ suggestions: {} })
    },
  }),
  useImportJob: () => ({
    data: {
      id: 'job-1', type: 'products', status: 'validated', original_filename: 'products.csv',
      total_rows: 7, processed_rows: 0, successful_rows: 0, skipped_rows: 0, failed_rows: 0,
      warning_rows: 0, warning_summary: null, error_summary: { unknown_units: [] }, progress_percentage: 0, error_message: null,
      started_at: null, completed_at: null, created_at: '2026-08-31T00:00:00Z',
    },
    refetch: vi.fn(),
  }),
  useImportErrors: () => ({ data: { data: [] } }),
  useImportPreview: () => ({
    data: mockPreview.current,
    isLoading: false, isError: false, refetch: vi.fn(),
  }),
}))
vi.mock('../components/FileUpload', () => ({
  FileUpload: ({ onFileSelect }: { onFileSelect: (file: File) => Promise<void> }) => (
    <button type="button" onClick={() => { void onFileSelect(new File(['name'], 'products.csv')) }}>choose</button>
  ),
}))
vi.mock('../components/ColumnMapper', () => ({
  ColumnMapper: ({ onMappingChange }: { onMappingChange: (mapping: Record<string, string>) => void }) => (
    <button type="button" onClick={() => { onMappingChange({ name: 'name' }) }}>map</button>
  ),
}))
vi.mock('../components/ValidationGrid', () => ({ ValidationGrid: () => null }))
vi.mock('../components/ImportProgress', () => ({ ImportProgress: () => null }))
vi.mock('../components/ImportPreviewTable', () => ({ ImportPreviewTable: () => null }))

describe('ImportWizardPage duplicate policy', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuthStore.setState({
      user: { id: 'user-1', name: 'User', email: 'user@example.com', tenant_id: 'tenant-1', roles: [], email_verified_at: null },
      token: 'token', isAuthenticated: true, isLoading: false,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
    useImportProgressStore.setState({ activeImports: new Map(), completedImportIds: new Set() })
    mockParseHeaders.mockResolvedValue({ headers: ['name'], row_count: 1 })
    mockCreateMutate.mockImplementation((_variables: unknown, options?: { onSuccess?: (data: { data: { id: string } }) => void }) => {
      options?.onSuccess?.({ data: { id: 'job-1' } })
    })
    mockUpdateOptions.mockResolvedValue({ data: { id: 'job-1' } })
    mockDeleteJob.mockResolvedValue(undefined)
    mockPreview.current = {
      headers: ['name'], rows: [],
      summary: { total_rows: 7, valid_rows: 7, invalid_rows: 0 },
      error_summary: { unknown_units: [] },
      duplicates: {
        counts: { new: 1, existing_sku: 2, existing_barcode: 1, existing_name: 1, in_file: 1, refused: 1 },
        matched_by_name: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
        refused: [{ row_number: 7, code: 'sku_held_by_deleted_product' }],
      },
    }
  })

  it('asserts the selector contract, persists once, and crosses the execute boundary', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))

    expect(await screen.findAllByTestId('import-preview-duplicate-summary')).toHaveLength(1)
    expect(screen.getByTestId('import-preview-duplicate-summary')).toHaveTextContent('duplicates.blankCells')
    expect(screen.getByTestId('import-preview-refused-summary')).toHaveTextContent('duplicates.refusedSummary')
    expect(screen.getByTestId('import-preview-refused-summary')).toHaveTextContent('sku_held_by_deleted_product')
    expect(screen.getByTestId('import-wizard-step-preview')).toBeInTheDocument()
    expect(screen.getByTestId('import-preview-policy-override')).toBeInTheDocument()
    expect(screen.getByTestId('import-preview-policy-skip')).toBeInTheDocument()
    expect(screen.getByTestId('import-preview-policy-cancel')).toBeInTheDocument()
    expect(screen.getByText('duplicates.matchedByName')).toBeInTheDocument()
    expect(screen.getByText('1, 2, 3, 4, 5, 6, 7, 8, 9, 10')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'duplicates.showAll' }))
    expect(screen.getByText('1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11')).toBeInTheDocument()
    await user.click(screen.getByTestId('import-preview-policy-skip'))
    expect(mockUpdateOptions).not.toHaveBeenCalled()
    await user.click(screen.getByTestId('import-wizard-next'))

    await waitFor(() => {
      expect(mockUpdateOptions).toHaveBeenCalledWith('job-1', { duplicate_policy: 'skip' })
    })
    expect(mockUpdateOptions).toHaveBeenCalledTimes(1)
    expect(await screen.findByTestId('import-wizard-step-execute')).toBeInTheDocument()
    expect(screen.getByTestId('import-wizard-execute')).toBeInTheDocument()
    await user.click(screen.getByTestId('import-wizard-execute'))
    expect(mockExecuteMutate).toHaveBeenCalledWith('job-1', expect.any(Object))
  })

  it('shows the parallel barcode census and persists explicit multi-location confirmation', async () => {
    if (mockPreview.current.duplicates) {
      mockPreview.current.duplicates.barcode_groups = {
        counts: { multi_location_products: 1, barcode_identity_conflict_groups: 1, barcode_identity_conflict_rows: 2 },
        groups: [
          { barcode: '6191000000000', classification: 'multi_location', row_numbers: [2, 3], location_codes: ['MAIN', 'ANNEX'], differing_fields: [] },
          { barcode: '6192000000000', classification: 'barcode_identity_conflict', row_numbers: [5, 6], location_codes: ['MAIN'], differing_fields: ['name', 'sku'] },
        ],
        rows: { 2: 0, 3: 0, 5: 1, 6: 1 },
      }
    }
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))

    expect(await screen.findByTestId('import-preview-multi-location-summary')).toHaveTextContent('duplicates.barcode.multiLocation')
    expect(screen.getByTestId('import-preview-barcode-conflict-summary')).toHaveTextContent('6192000000000')
    expect(screen.getByTestId('import-preview-barcode-conflict-summary')).toHaveTextContent('name, sku')
    await user.click(screen.getByTestId('import-wizard-next'))
    expect(await screen.findByText('duplicates.multiLocationConfirm.title')).toBeInTheDocument()
    expect(mockUpdateOptions).not.toHaveBeenCalled()
    await user.click(screen.getByTestId('confirm-dialog-confirm'))

    await waitFor(() => {
      expect(mockUpdateOptions).toHaveBeenCalledWith('job-1', {
        duplicate_policy: 'override',
        multi_location_confirmed: true,
      })
    })
    expect(await screen.findByTestId('import-wizard-step-execute')).toBeInTheDocument()
  })

  it('renders the conflict summary from the captured real XLSX preview response', async () => {
    mockPreview.current = realProductsPreviewResponse as ImportPreview
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))

    const conflictSummary = await screen.findByTestId('import-preview-barcode-conflict-summary')
    expect(conflictSummary).toHaveTextContent('duplicates.barcode.identityConflict')
    expect(conflictSummary).toHaveTextContent('6192430000000')
    expect(conflictSummary).toHaveTextContent('description, margin, name, purchase_price, sale_price_excl_tax, sku')
    expect(screen.queryByTestId('import-preview-multi-location-summary')).not.toBeInTheDocument()
  })

  it('keeps Next disabled and stays on preview until the policy PATCH resolves', async () => {
    if (mockPreview.current.duplicates) {
      mockPreview.current.duplicates.counts.refused = 0
      mockPreview.current.duplicates.refused = []
    }
    let resolvePatch: ((value: { data: { id: string } }) => void) | undefined
    mockUpdateOptions.mockImplementation(() => new Promise((resolve) => { resolvePatch = resolve }))
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))
    const next = await screen.findByTestId('import-wizard-next')
    expect(screen.queryByTestId('import-preview-refused-summary')).not.toBeInTheDocument()
    await user.click(next)

    expect(next).toBeDisabled()
    expect(screen.getByTestId('import-wizard-step-preview')).toBeInTheDocument()
    resolvePatch?.({ data: { id: 'job-1' } })
    expect(await screen.findByTestId('import-wizard-step-execute')).toBeInTheDocument()
  })

  it('shows skip-only completion as zero imported, five skipped, and zero failed', async () => {
    const completedJob: ImportJob & { import_result: ImportResult } = {
      id: 'job-1',
      type: 'products',
      status: 'completed',
      original_filename: 'products.csv',
      total_rows: 5,
      processed_rows: 5,
      successful_rows: 0,
      skipped_rows: 5,
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: null,
      error_summary: { unknown_units: [] },
      progress_percentage: 100,
      error_code: null,
      error_message: null,
      started_at: '2026-08-31T00:00:00Z',
      completed_at: '2026-08-31T00:00:01Z',
      created_at: '2026-08-30T23:59:59Z',
      import_result: {
        imported_count: 0,
        skipped_count: 5,
        execution_error_count: 0,
        preview_drift_count: 0,
        total_rows: 5,
        failed_rows_csv_url: null,
      },
    }
    mockExecuteMutate.mockImplementation((
      _jobId: string,
      options?: { onSuccess?: (data: ImportJob & { import_result?: ImportResult }) => void },
    ) => {
      options?.onSuccess?.(completedJob)
    })
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))
    await user.click(screen.getByTestId('import-preview-policy-skip'))
    await user.click(screen.getByTestId('import-wizard-next'))
    await user.click(await screen.findByTestId('import-wizard-execute'))

    expect(await screen.findByTestId('import-complete-count-imported')).toHaveTextContent('0')
    expect(screen.getByTestId('import-complete-count-skipped')).toHaveTextContent('5')
    expect(screen.getByTestId('import-complete-count-failed')).toHaveTextContent('0')
    expect(screen.getByText('wizard.complete.noChanges')).toBeInTheDocument()
  })

  it('hides duplicate policy when preview has no census and advances without PATCH', async () => {
    mockPreview.current = {
      headers: ['name'], rows: [],
      summary: { total_rows: 7, valid_rows: 7, invalid_rows: 0 },
      error_summary: { unknown_units: [] },
    }
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))

    expect(screen.queryByTestId('import-preview-duplicate-summary')).not.toBeInTheDocument()
    expect(screen.queryByTestId('import-preview-policy-override')).not.toBeInTheDocument()
    await user.click(screen.getByTestId('import-wizard-next'))
    expect(mockUpdateOptions).not.toHaveBeenCalled()
    expect(await screen.findByTestId('import-wizard-step-execute')).toBeInTheDocument()
  })

  it('surfaces a failed policy PATCH and does not advance', async () => {
    mockUpdateOptions.mockRejectedValue(new Error('save failed'))
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))
    await user.click(screen.getByTestId('import-wizard-next'))

    expect(await screen.findByRole('alert')).toHaveTextContent('duplicates.policy.persistenceError')
    expect(screen.getByTestId('import-wizard-step-preview')).toBeInTheDocument()
    expect(screen.queryByTestId('import-wizard-execute')).not.toBeInTheDocument()
  })

  it('routes Cancel through discard confirmation and deletes only after confirmation', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const Wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/settings/import/products']}>
        <Routes><Route path="/settings/import/:type" element={<ImportWizardPage />} /></Routes>
      </MemoryRouter>,
      { wrapper: Wrapper },
    )

    await user.click(screen.getByRole('button', { name: 'choose' }))
    await user.click(await screen.findByTestId('import-wizard-next'))
    await user.click(screen.getByRole('button', { name: 'map' }))
    await user.click(screen.getByTestId('import-wizard-validate'))
    await user.click(screen.getByTestId('import-preview-policy-cancel'))

    expect(screen.getByText('duplicates.discard.title')).toBeInTheDocument()
    expect(mockDeleteJob).not.toHaveBeenCalled()
    await user.click(screen.getByTestId('confirm-dialog-confirm'))
    await waitFor(() => { expect(mockDeleteJob).toHaveBeenCalledWith('job-1') })
  })
})
