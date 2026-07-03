import { api, apiPost, apiGet } from '@/lib/api'
import type {
  ImportJob,
  ImportJobListResponse,
  ImportErrorsResponse,
  ImportErrorSummaryResponse,
  ImportResult,
  CreateImportResponse,
  MigrationWizardOrder,
  DependencyCheck,
  ColumnMappingSuggestions,
  MigrationStatus,
  ImportType,
  ImportPreview,
} from '../types'

const IMPORT_URL = '/imports'
const WIZARD_URL = '/migration-wizard'

export const importApi = {
  // Import Jobs
  list: async (): Promise<ImportJobListResponse> => {
    // Paginated {data, meta} envelope — apiGet would unwrap to the array
    // and drop meta, so use the raw client and return response.data intact.
    const response = await api.get<ImportJobListResponse>(IMPORT_URL)
    return response.data
  },

  getJob: async (id: string): Promise<ImportJob> => {
    return apiGet<ImportJob>(`${IMPORT_URL}/${id}`)
  },

  createJob: async (
    type: ImportType,
    file: File,
    columnMapping?: Record<string, string>
  ): Promise<CreateImportResponse> => {
    const formData = new FormData()
    formData.append('type', type)
    formData.append('file', file)
    if (columnMapping && Object.keys(columnMapping).length > 0) {
      formData.append('column_mapping', JSON.stringify(columnMapping))
    }

    // Large files need more time for parsing + validation (up to 2 minutes)
    const response = await api.post<CreateImportResponse>(IMPORT_URL, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    })
    return response.data
  },

  getErrors: async (jobId: string, page?: number, perPage?: number): Promise<ImportErrorsResponse> => {
    const params = new URLSearchParams()
    if (page) params.set('page', page.toString())
    if (perPage) params.set('per_page', perPage.toString())

    const url = params.toString()
      ? `${IMPORT_URL}/${jobId}/errors?${params.toString()}`
      : `${IMPORT_URL}/${jobId}/errors`

    // Paginated {data, meta} envelope — keep it intact (see list()).
    const response = await api.get<ImportErrorsResponse>(url)
    return response.data
  },

  getErrorSummary: async (jobId: string): Promise<ImportErrorSummaryResponse> => {
    return apiGet<ImportErrorSummaryResponse>(`${IMPORT_URL}/${jobId}/error-summary`)
  },

  downloadFailedRowsUrl: (jobId: string): string => {
    return `${IMPORT_URL}/${jobId}/failed-rows.csv`
  },

  getPreview: async (jobId: string): Promise<ImportPreview> => {
    return apiGet<ImportPreview>(`${IMPORT_URL}/${jobId}/preview`)
  },

  executeImport: async (jobId: string): Promise<ImportJob & { import_result?: ImportResult }> => {
    // import_result sits BESIDE data in the execute response — plain apiPost
    // unwrapping would drop it, so merge it back onto the job.
    const response = await api.post<{ data: ImportJob; import_result?: ImportResult }>(
      `${IMPORT_URL}/${jobId}/execute`
    )
    const job: ImportJob & { import_result?: ImportResult } = { ...response.data.data }
    if (response.data.import_result) {
      job.import_result = response.data.import_result
    }
    return job
  },

  deleteJob: async (jobId: string): Promise<void> => {
    await api.delete(`${IMPORT_URL}/${jobId}`)
  },

  parseHeaders: async (file: File): Promise<{ headers: string[]; row_count: number }> => {
    const formData = new FormData()
    formData.append('file', file)

    const response = await api.post<{ data: { headers: string[]; row_count: number } }>(
      `${WIZARD_URL}/parse-headers`,
      formData,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 120000,
      }
    )
    return response.data.data
  },

  // Migration Wizard
  getWizardOrder: async (): Promise<MigrationWizardOrder> => {
    const response = await apiGet<MigrationWizardOrder>(`${WIZARD_URL}/order`)
    return response
  },

  checkDependencies: async (type: ImportType): Promise<DependencyCheck> => {
    return apiGet<DependencyCheck>(`${WIZARD_URL}/dependencies/${type}`)
  },

  suggestMapping: async (
    type: ImportType,
    headers: string[]
  ): Promise<ColumnMappingSuggestions> => {
    return apiPost<ColumnMappingSuggestions>(
      `${WIZARD_URL}/suggest-mapping`,
      { type, headers }
    )
  },

  downloadTemplateUrl: (type: ImportType): string => {
    return `${WIZARD_URL}/template/${type}`
  },

  getMigrationStatus: async (): Promise<MigrationStatus> => {
    return apiGet<MigrationStatus>(`${WIZARD_URL}/status`)
  },
}
