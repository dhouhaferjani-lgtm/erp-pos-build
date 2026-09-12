import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ImportWizardPage } from '../pages/ImportWizardPage'
import type { ImportJob, ImportPreview } from '../types'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useImportProgressStore } from '@/stores/importProgressStore'

let nextMapping: Record<string, string> = {}
let nextJobData: ImportJob | undefined
let createdJobId = 'job-1'
const companyConfigState = vi.hoisted(() => ({ enrichmentAvailable: false }))

const mockGetJob = vi.hoisted(() => vi.fn())
const mockParseHeaders = vi.hoisted(() => vi.fn())
const mockUpdateOptions = vi.hoisted(() => vi.fn())
const mockCreateMutate = vi.hoisted(() => vi.fn())
const mockExecuteMutate = vi.hoisted(() => vi.fn())
const mockSuggestMutate = vi.hoisted(() => vi.fn())
const mockPreviewRequest = vi.hoisted(() => vi.fn<(jobId: string) => Promise<ImportPreview>>())
const mockRefetchJob = vi.hoisted(() => vi.fn())
const mockApiGet = vi.hoisted(() => vi.fn())
const mockToastError = vi.hoisted(() => vi.fn())
const mockToastInfo = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('sonner', () => ({
  toast: {
    error: mockToastError,
    info: mockToastInfo,
    success: vi.fn(),
  },
}))

vi.mock('@/lib/api', () => ({
  authenticatedDownload: vi.fn(),
  apiGet: mockApiGet,
}))

vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfigOptional: () => ({
    config: {
      platform_import_enrichment_available: companyConfigState.enrichmentAvailable,
    },
  }),
}))

vi.mock('../api/importApi', () => ({
  importApi: {
    getJob: mockGetJob,
    parseHeaders: mockParseHeaders,
    updateOptions: mockUpdateOptions,
    getPreview: mockPreviewRequest,
    downloadTemplateUrl: (type: string) => `/migration-wizard/template/${type}`,
  },
}))

vi.mock('../api/queries', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/queries')>()

  return {
    ...actual,
    useCreateImport: () => ({
      mutate: mockCreateMutate,
      isPending: false,
    }),
    useExecuteImport: () => ({
      mutate: mockExecuteMutate,
      isSuccess: false,
      isPending: false,
    }),
    useSuggestMapping: () => ({
      mutate: mockSuggestMutate,
    }),
    useImportJob: () => ({ data: nextJobData, refetch: mockRefetchJob }),
    useImportErrors: () => ({ data: { data: [] } }),
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

function renderWizard(path = '/settings/import/products') {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }

  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/settings/import/:type" element={<ImportWizardPage />} />
      </Routes>
    </MemoryRouter>,
    { wrapper: Wrapper },
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
  it('keeps Next disabled until the original re-import mapping has loaded', async () => {
    let resolveMapping!: (value: { column_mapping: Record<string, string> }) => void
    mockGetJob.mockReturnValueOnce(new Promise((resolve) => { resolveMapping = resolve }))
    renderWizard('/settings/import/products?reimport_of=original-job')
    await userEvent.click(screen.getByRole('button', { name: 'choose-file' }))
    await waitFor(() => expect(mockGetJob).toHaveBeenCalledWith('original-job'))
    expect(screen.getByRole('button', { name: 'common:actions.next' })).toBeDisabled()
    await act(async () => { resolveMapping({ column_mapping: { name: 'name' } }) })
    expect(screen.getByRole('button', { name: 'common:actions.next' })).toBeEnabled()
    await userEvent.click(screen.getByRole('button', { name: 'common:actions.next' }))
    expect(screen.queryByRole('button', { name: 'apply-mapping' })).not.toBeInTheDocument()
  })

  it('keeps the operator\'s file when the original import cannot be read (BUG-004 class)', async () => {
    // A purged or foreign original 404s. That is not "your file is invalid":
    // it used to fall into the parse-headers catch, which reported
    // wizard.upload.serverError and cleared the selection, leaving no way forward.
    mockGetJob.mockRejectedValueOnce(Object.assign(new Error('not found'), { isAxiosError: true, response: { status: 404 } }))
    renderWizard('/settings/import/products?reimport_of=purged-job')

    await userEvent.click(screen.getByRole('button', { name: 'choose-file' }))

    await screen.findByText('wizard.upload.fileReady')
    await waitFor(() => { expect(mockToastInfo).toHaveBeenCalledWith('correction.originalUnavailable') })
    expect(mockToastError).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'common:actions.next' })).toBeEnabled()

    await userEvent.click(screen.getByRole('button', { name: 'common:actions.next' }))
    expect(screen.getByRole('button', { name: 'apply-mapping' })).toBeInTheDocument()
  })

  it('does not skip the mapping step when the saved mapping misses a required target', async () => {
    // m7: the old guard only checked that the saved SOURCE headers still exist.
    // A mapping that no longer covers the required targets skipped straight into
    // a guaranteed 422 the operator could not act on.
    mockGetJob.mockResolvedValueOnce({ column_mapping: { margin: 'margin' } })
    renderWizard('/settings/import/products?reimport_of=original-job')

    await userEvent.click(screen.getByRole('button', { name: 'choose-file' }))
    await screen.findByText('wizard.upload.fileReady')
    await userEvent.click(screen.getByRole('button', { name: 'common:actions.next' }))

    expect(screen.getByRole('button', { name: 'apply-mapping' })).toBeInTheDocument()
  })

  it('surfaces the server\'s reimport_notice rather than re-deciding the reuse rule', async () => {
    // The "re-apply the original mapping unless the headers changed" rule has ONE
    // writer: ImportController::store. The wizard reads its verdict.
    mockCreateMutate.mockImplementation((_variables: unknown, options?: { onSuccess?: (data: { data: { id: string }; reimport_notice?: string | null }) => void }) => {
      options?.onSuccess?.({ data: { id: createdJobId }, reimport_notice: 'reimport_headers_changed' })
    })
    mockGetJob.mockResolvedValueOnce({ column_mapping: { name: 'name' } })
    renderWizard('/settings/import/products?reimport_of=original-job')

    await userEvent.click(screen.getByRole('button', { name: 'choose-file' }))
    await screen.findByText('wizard.upload.fileReady')
    await userEvent.click(screen.getByRole('button', { name: 'common:actions.next' }))

    await waitFor(() => { expect(mockToastInfo).toHaveBeenCalledWith('correction.headersChanged') })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  beforeEach(() => {
    vi.clearAllMocks()
    nextMapping = {}
    nextJobData = undefined
    createdJobId = 'job-1'
    companyConfigState.enrichmentAvailable = false
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Import User',
        email: 'import@example.com',
        tenant_id: 'tenant-1',
        roles: [],
        email_verified_at: null,
      },
      token: 'token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({
      currentCompanyId: 'company-1',
      companies: [],
      isLoading: false,
    })
    useImportProgressStore.setState({
      activeImports: new Map(),
      completedImportIds: new Set(),
    })
    mockApiGet.mockResolvedValue([])
    mockParseHeaders.mockResolvedValue({
      headers: ['name', 'price_ttc', 'price_ht', 'margin'],
      row_count: 1,
    })
    mockSuggestMutate.mockImplementation((_variables: unknown, options?: { onSuccess?: (data: { suggestions: Record<string, string> }) => void }) => {
      options?.onSuccess?.({ suggestions: {} })
    })
    mockCreateMutate.mockImplementation((_variables: unknown, options?: { onSuccess?: (data: { data: { id: string } }) => void }) => {
      options?.onSuccess?.({ data: { id: createdJobId } })
    })
    mockUpdateOptions.mockResolvedValue({ data: { id: 'job-1' } })
    mockPreviewRequest.mockImplementation(() => Promise.resolve({
      headers: [],
      rows: [],
      summary: {
        total_rows: nextJobData?.total_rows ?? 1,
        valid_rows: nextJobData
          ? nextJobData.total_rows - nextJobData.failed_rows
          : 1,
        invalid_rows: nextJobData?.failed_rows ?? 0,
      },
      error_summary: nextJobData?.error_summary ?? { unknown_units: [] },
      placement: { max_depth: 3, nodes_to_create: [], placements_to_set: [] },
    }))
    mockRefetchJob.mockResolvedValue({ data: nextJobData })
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

  it('states both counts on the partial-import confirmation action', async () => {
    nextMapping = { name: 'name' }
    nextJobData = {
      id: 'job-1',
      type: 'products',
      status: 'validated',
      original_filename: 'products.csv',
      total_rows: 4,
      processed_rows: 2,
      successful_rows: 0,
      skipped_rows: 0,
      failed_rows: 2,
      warning_rows: 0,
      warning_summary: null,
      error_summary: { unknown_units: [] },
      progress_percentage: 50,
      options: null,
      error_code: null,
      error_message: null,
      started_at: null,
      completed_at: null,
      created_at: '2026-08-31T10:00:00Z',
    }

    await uploadAndMap()
    await userEvent.setup().click(screen.getByRole('button', { name: 'wizard.validation.proceed' }))

    expect(screen.getByTestId('confirm-dialog-confirm')).toHaveTextContent('wizard.proceedWithValidCounts')
  })

  it('keeps the validation action disabled when no row can be imported', async () => {
    nextMapping = { name: 'name' }
    nextJobData = {
      id: 'job-1',
      type: 'products',
      status: 'validated',
      original_filename: 'real-produits.xlsx',
      total_rows: 859,
      processed_rows: 859,
      successful_rows: 0,
      skipped_rows: 0,
      failed_rows: 859,
      warning_rows: 0,
      warning_summary: null,
      error_summary: { unknown_units: [] },
      progress_percentage: 100,
      options: null,
      error_code: null,
      error_message: null,
      started_at: null,
      completed_at: null,
      created_at: '2026-08-31T10:00:00Z',
    }

    await uploadAndMap()

    expect(screen.getByRole('button', { name: 'wizard.validation.proceed' })).toBeDisabled()
    expect(screen.getByText('wizard.validation.noValidRows')).toBeInTheDocument()
  })

  it('shows import enrichment for a capable company and persists an explicit opt-in', async () => {
    companyConfigState.enrichmentAvailable = true
    nextMapping = {
      name: 'name',
      barcode: 'barcode',
    }

    await uploadAndMap()

    expect(await screen.findByRole('heading', { name: 'options.enrichmentTitle' })).toBeInTheDocument()
    const enrichmentToggle = screen.getByRole('checkbox', { name: 'options.enrichmentLabel' })
    expect(enrichmentToggle).not.toBeChecked()

    await userEvent.setup().click(enrichmentToggle)
    await userEvent.setup().click(screen.getByRole('button', { name: 'common:actions.next' }))

    await waitFor(() => {
      expect(mockUpdateOptions).toHaveBeenCalledWith('job-1', {
        enrichment_enabled: true,
      })
    })
  })

  it('hides import enrichment when the company capability is unavailable', async () => {
    nextMapping = {
      name: 'name',
      barcode: 'barcode',
    }

    await uploadAndMap()

    expect(await screen.findByRole('heading', { name: 'wizard.validation.title' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'options.enrichmentTitle' })).not.toBeInTheDocument()
  })

  it('hides import enrichment when barcode is not mapped for a capable company', async () => {
    companyConfigState.enrichmentAvailable = true
    nextMapping = {
      name: 'name',
    }

    await uploadAndMap()

    expect(await screen.findByRole('heading', { name: 'wizard.validation.title' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'options.enrichmentTitle' })).not.toBeInTheDocument()
  })

  it('requests one preview with the job id and shows no error toast during a green import', async () => {
    const completedJob: ImportJob = {
      id: 'job-1',
      type: 'products',
      status: 'completed',
      original_filename: 'products.csv',
      total_rows: 1,
      processed_rows: 1,
      successful_rows: 1,
      skipped_rows: 0,
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: {},
      error_summary: { unknown_units: [] },
      progress_percentage: 100,
      options: null,
      error_code: null,
      error_message: null,
      started_at: '2026-08-31T10:00:00Z',
      completed_at: '2026-08-31T10:00:01Z',
      created_at: '2026-08-31T09:59:59Z',
    }
    mockExecuteMutate.mockImplementation((_jobId: string, options?: { onSuccess?: (data: ImportJob) => void }) => {
      options?.onSuccess?.(completedJob)
    })
    nextMapping = {
      name: 'name',
      price_ttc: 'sale_price_incl_tax',
      price_ht: 'sale_price_excl_tax',
    }

    await uploadAndMap()
    expect(await screen.findByRole('heading', { name: 'options.priceAuthorityTitle' })).toBeInTheDocument()
    expect(mockPreviewRequest).not.toHaveBeenCalled()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'common:actions.next' }))

    expect(await screen.findByRole('heading', { name: 'wizard.validation.title' })).toBeInTheDocument()
    expect(mockPreviewRequest).toHaveBeenCalledOnce()
    expect(mockPreviewRequest).toHaveBeenCalledWith('job-1')

    await user.click(screen.getByRole('button', { name: 'wizard.validation.proceed' }))
    await user.click(screen.getByRole('button', { name: 'wizard.execute.start' }))

    expect(await screen.findByRole('heading', { name: 'wizard.complete.title' })).toBeInTheDocument()
    expect(mockToastError).not.toHaveBeenCalled()
  })

  it('does not request a preview when the import job id is empty', async () => {
    createdJobId = ''
    nextMapping = {
      name: 'name',
      price_ttc: 'sale_price_incl_tax',
      price_ht: 'sale_price_excl_tax',
    }

    await uploadAndMap()
    expect(await screen.findByRole('heading', { name: 'options.priceAuthorityTitle' })).toBeInTheDocument()

    await userEvent.setup().click(screen.getByRole('button', { name: 'common:actions.next' }))

    expect(mockUpdateOptions).not.toHaveBeenCalled()
    expect(mockPreviewRequest).not.toHaveBeenCalled()
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
    expect(mockPreviewRequest).toHaveBeenCalledTimes(2)
    expect(mockPreviewRequest).toHaveBeenNthCalledWith(1, 'job-1')
    expect(mockPreviewRequest).toHaveBeenNthCalledWith(2, 'job-1')
  })

  it('defaults opening stock to the default coded location and patches its code', async () => {
    nextMapping = {
      name: 'name',
      quantity: 'quantity',
    }
    mockApiGet.mockResolvedValue([
      { id: 'branch', name: 'Branch', code: 'BRANCH', type: 'warehouse', is_default: false, is_active: true },
      { id: 'main', name: 'Main Location', code: 'MAIN', type: 'warehouse', is_default: true, is_active: true },
    ])

    await uploadAndMap()

    const stockLocation = await screen.findByLabelText('options.stockLocation.label')
    await waitFor(() => {
      expect(stockLocation).toHaveValue('MAIN')
    })

    await userEvent.setup().click(screen.getByRole('button', { name: 'common:actions.next' }))

    await waitFor(() => {
      expect(mockUpdateOptions).toHaveBeenCalledWith('job-1', {
        location_code: 'MAIN',
      })
    })
  })

  it('uses scoped company locations, disables a null code, and excludes inactive locations', async () => {
    nextMapping = {
      name: 'name',
      quantity: 'quantity',
    }
    mockApiGet.mockResolvedValue([
      { id: 'main', name: 'Main Location', code: 'MAIN', type: 'warehouse', is_default: true, is_active: true },
      { id: 'uncoded', name: 'Uncoded Branch', code: null, type: 'shop', is_default: false, is_active: true },
      { id: 'inactive', name: 'Inactive Branch', code: 'OLD', type: 'shop', is_default: false, is_active: false },
    ])

    await uploadAndMap()

    expect(mockApiGet).toHaveBeenCalledWith('/company/locations')
    expect(await screen.findByRole('option', {
      name: 'Uncoded Branch — options.stockLocation.noCode',
    })).toBeDisabled()
    expect(screen.queryByRole('option', { name: 'Inactive Branch (OLD)' })).not.toBeInTheDocument()
  })

  it('refetches the finalized job before showing WebSocket completion warnings', async () => {
    nextMapping = { name: 'name' }
    const staleJob: ImportJob = {
      id: 'job-1',
      type: 'products',
      status: 'validated',
      original_filename: 'products.csv',
      total_rows: 100,
      processed_rows: 0,
      successful_rows: 100,
      skipped_rows: 0,
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: {},
      error_summary: { unknown_units: [] },
      progress_percentage: 0,
      options: null,
      error_code: null,
      error_message: null,
      started_at: null,
      completed_at: null,
      created_at: '2026-08-29T09:59:59Z',
    }
    nextJobData = staleJob
    mockExecuteMutate.mockImplementation((_jobId: string, options?: { onSuccess?: (data: ImportJob) => void }) => {
      options?.onSuccess?.({ ...staleJob, status: 'pending' })
    })

    let finishRefetch: (() => void) | undefined
    mockRefetchJob.mockImplementation(() => new Promise((resolve) => {
      finishRefetch = () => {
        nextJobData = {
          ...staleJob,
          status: 'completed',
          processed_rows: 100,
          progress_percentage: 100,
          warning_rows: 2,
          warning_summary: { location_unresolved: 2 },
          completed_at: '2026-08-29T10:00:01Z',
        }
        resolve({ data: nextJobData })
      }
    }))

    await uploadAndMap()
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'wizard.validation.proceed' }))
    await user.click(screen.getByRole('button', { name: 'wizard.execute.start' }))

    act(() => {
      useImportProgressStore.getState().updateProgress({
        import_job_id: 'job-1',
        status: 'completed',
        total_rows: 100,
        processed_rows: 100,
        successful_rows: 100,
        failed_rows: 0,
        progress_percentage: 100,
        import_type: 'products',
        original_filename: 'products.csv',
      })
    })

    await waitFor(() => { expect(mockRefetchJob).toHaveBeenCalledOnce() })
    expect(screen.queryByRole('heading', { name: 'wizard.complete.warnings' })).not.toBeInTheDocument()

    await act(async () => {
      finishRefetch?.()
      await Promise.resolve()
    })

    expect(await screen.findByRole('heading', { name: 'wizard.complete.warnings' })).toBeInTheDocument()
    expect(screen.getByText('warnings.location_unresolved')).toBeInTheDocument()
  })

  it('reaches complete when the final refetch rejects after API data is already completed', async () => {
    nextMapping = { name: 'name' }
    const staleJob: ImportJob = {
      id: 'job-1',
      type: 'products',
      status: 'validated',
      original_filename: 'products.csv',
      total_rows: 100,
      processed_rows: 0,
      successful_rows: 100,
      skipped_rows: 0,
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: {},
      error_summary: { unknown_units: [] },
      progress_percentage: 0,
      options: null,
      error_code: null,
      error_message: null,
      started_at: null,
      completed_at: null,
      created_at: '2026-08-29T09:59:59Z',
    }
    const completedJob: ImportJob = {
      ...staleJob,
      status: 'completed',
      processed_rows: 100,
      progress_percentage: 100,
      warning_rows: 2,
      warning_summary: { location_unresolved: 2 },
      completed_at: '2026-08-29T10:00:01Z',
    }
    nextJobData = staleJob
    mockExecuteMutate.mockImplementation((_jobId: string, options?: { onSuccess?: (data: ImportJob) => void }) => {
      options?.onSuccess?.({ ...staleJob, status: 'pending' })
    })
    const refetchError = new Error('final refetch failed')
    mockRefetchJob.mockRejectedValueOnce(refetchError)
    const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined)

    await uploadAndMap()
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'wizard.validation.proceed' }))
    await user.click(screen.getByRole('button', { name: 'wizard.execute.start' }))

    nextJobData = completedJob
    act(() => {
      useImportProgressStore.getState().updateProgress({
        import_job_id: 'job-1',
        status: 'completed',
        total_rows: 100,
        processed_rows: 100,
        successful_rows: 100,
        failed_rows: 0,
        progress_percentage: 100,
        import_type: 'products',
        original_filename: 'products.csv',
      })
    })

    await waitFor(() => { expect(mockRefetchJob).toHaveBeenCalledOnce() })
    expect(await screen.findByRole('heading', { name: 'wizard.complete.title' })).toBeInTheDocument()
    expect(consoleErrorSpy).toHaveBeenCalledOnce()
    expect(consoleErrorSpy).toHaveBeenCalledWith('Import wizard: final job refetch failed', refetchError)
  })

  it.each(['failed', 'partially_completed'] as const)('refetches a %s job before transitioning to complete', async (status) => {
    nextMapping = { name: 'name' }
    const staleJob: ImportJob = {
      id: 'job-1',
      type: 'products',
      status: 'validated',
      original_filename: 'products.csv',
      total_rows: 100,
      processed_rows: 0,
      successful_rows: 0,
      skipped_rows: 0,
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: {},
      error_summary: { unknown_units: [] },
      progress_percentage: 0,
      options: null,
      error_code: null,
      error_message: null,
      started_at: null,
      completed_at: null,
      created_at: '2026-08-29T09:59:59Z',
    }
    const failedJob: ImportJob = {
      ...staleJob,
      status,
      processed_rows: 100,
      failed_rows: status === 'failed' ? 100 : 0,
      successful_rows: status === 'partially_completed' ? 100 : 0,
      progress_percentage: 100,
      error_code: null,
      error_message: 'Import failed',
      completed_at: '2026-08-29T10:00:01Z',
    }
    nextJobData = staleJob
    mockExecuteMutate.mockImplementation((_jobId: string, options?: { onSuccess?: (data: ImportJob) => void }) => {
      options?.onSuccess?.({ ...staleJob, status: 'pending' })
    })

    let finishRefetch: (() => void) | undefined
    mockRefetchJob.mockImplementation(() => new Promise((resolve) => {
      finishRefetch = () => {
        nextJobData = failedJob
        resolve({ data: failedJob, isError: false })
      }
    }))

    await uploadAndMap()
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'wizard.validation.proceed' }))
    await user.click(screen.getByRole('button', { name: 'wizard.execute.start' }))

    act(() => {
      useImportProgressStore.getState().updateProgress({
        import_job_id: 'job-1',
        status,
        total_rows: 100,
        processed_rows: 100,
        failed_rows: status === 'failed' ? 100 : 0,
        successful_rows: status === 'partially_completed' ? 100 : 0,
        progress_percentage: 100,
        import_type: 'products',
        original_filename: 'products.csv',
      })
    })

    await waitFor(() => { expect(mockRefetchJob).toHaveBeenCalledOnce() })
    expect(screen.queryByRole('heading', { name: 'wizard.complete.title' })).not.toBeInTheDocument()

    await act(async () => {
      finishRefetch?.()
      await Promise.resolve()
    })

    expect(await screen.findByRole('heading', { name: `status.${status}` })).toBeInTheDocument()
  })

  it('shows warning counts on the completion step', async () => {
    nextMapping = { name: 'name' }
    nextJobData = {
      id: 'job-1',
      type: 'products',
      status: 'completed',
      original_filename: 'products.csv',
      total_rows: 2,
      processed_rows: 2,
      successful_rows: 2,
      skipped_rows: 0,
      failed_rows: 0,
      warning_rows: 2,
      warning_summary: { location_unresolved: 2 },
      error_summary: { unknown_units: [] },
      progress_percentage: 100,
      options: null,
      error_code: null,
      error_message: null,
      started_at: '2026-08-29T10:00:00Z',
      completed_at: '2026-08-29T10:00:01Z',
      created_at: '2026-08-29T09:59:59Z',
    }

    await uploadAndMap()
    await userEvent.setup().click(await screen.findByRole('button', {
      name: 'wizard.validation.proceed',
    }))

    expect(await screen.findByRole('heading', { name: 'wizard.complete.warnings' })).toBeInTheDocument()
    expect(screen.getByText('warnings.location_unresolved')).toBeInTheDocument()
  })

  it('renders enriched products separately from durable enrichment warnings', async () => {
    nextMapping = { name: 'name' }
    nextJobData = {
      id: 'job-1',
      type: 'products',
      status: 'completed',
      original_filename: 'products.csv',
      total_rows: 2,
      processed_rows: 2,
      successful_rows: 2,
      skipped_rows: 0,
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: { enriched: 1, enrichment_not_found: 1, enrichment_barcode_missing: 1 },
      error_summary: { unknown_units: [] },
      progress_percentage: 100,
      options: null,
      error_code: null,
      error_message: null,
      started_at: '2026-08-29T10:00:00Z',
      completed_at: '2026-08-29T10:00:01Z',
      created_at: '2026-08-29T09:59:59Z',
    }

    await uploadAndMap()
    await userEvent.setup().click(await screen.findByRole('button', {
      name: 'wizard.validation.proceed',
    }))

    expect(await screen.findByText('wizard.complete.enriched')).toBeInTheDocument()
    expect(screen.getByText('warnings.enrichment_not_found')).toBeInTheDocument()
    expect(screen.getByText('warnings.enrichment_barcode_missing')).toBeInTheDocument()
    expect(screen.queryByText('warnings.enriched')).not.toBeInTheDocument()
  })
})
