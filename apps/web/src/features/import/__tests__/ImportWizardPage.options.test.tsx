import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ImportWizardPage } from '../pages/ImportWizardPage'
import type { ImportJob } from '../types'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useImportProgressStore } from '@/stores/importProgressStore'

let nextMapping: Record<string, string> = {}
let nextJobData: ImportJob | undefined

const mockParseHeaders = vi.hoisted(() => vi.fn())
const mockUpdateOptions = vi.hoisted(() => vi.fn())
const mockCreateMutate = vi.hoisted(() => vi.fn())
const mockExecuteMutate = vi.hoisted(() => vi.fn())
const mockSuggestMutate = vi.hoisted(() => vi.fn())
const mockRefetchPreview = vi.hoisted(() => vi.fn())
const mockRefetchJob = vi.hoisted(() => vi.fn())
const mockApiGet = vi.hoisted(() => vi.fn())

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
  apiGet: mockApiGet,
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
    mutate: mockExecuteMutate,
    isSuccess: false,
    isPending: false,
  }),
  useSuggestMapping: () => ({
    mutate: mockSuggestMutate,
  }),
  useImportJob: () => ({ data: nextJobData, refetch: mockRefetchJob }),
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
    <MemoryRouter initialEntries={['/settings/import/products']}>
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
  afterEach(() => {
    vi.restoreAllMocks()
  })

  beforeEach(() => {
    vi.clearAllMocks()
    nextMapping = {}
    nextJobData = undefined
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
      options?.onSuccess?.({ data: { id: 'job-1' } })
    })
    mockUpdateOptions.mockResolvedValue({ data: { id: 'job-1' } })
    mockRefetchPreview.mockResolvedValue({ data: undefined })
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
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: {},
      progress_percentage: 0,
      options: null,
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
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: {},
      progress_percentage: 0,
      options: null,
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

  it('refetches a failed job before transitioning to complete', async () => {
    nextMapping = { name: 'name' }
    const staleJob: ImportJob = {
      id: 'job-1',
      type: 'products',
      status: 'validated',
      original_filename: 'products.csv',
      total_rows: 100,
      processed_rows: 0,
      successful_rows: 0,
      failed_rows: 0,
      warning_rows: 0,
      warning_summary: {},
      progress_percentage: 0,
      options: null,
      error_message: null,
      started_at: null,
      completed_at: null,
      created_at: '2026-08-29T09:59:59Z',
    }
    const failedJob: ImportJob = {
      ...staleJob,
      status: 'failed',
      processed_rows: 100,
      failed_rows: 100,
      progress_percentage: 100,
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
        status: 'failed',
        total_rows: 100,
        processed_rows: 100,
        successful_rows: 0,
        failed_rows: 100,
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

    expect(await screen.findByRole('heading', { name: 'wizard.complete.title' })).toBeInTheDocument()
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
      failed_rows: 0,
      warning_rows: 2,
      warning_summary: { location_unresolved: 2 },
      progress_percentage: 100,
      options: null,
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
})
