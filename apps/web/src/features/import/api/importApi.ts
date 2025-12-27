import { api, apiPost, apiGet } from '@/lib/api'
import type {
  ImportJob,
  ImportJobListResponse,
  ImportErrorsResponse,
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
    const response = await apiGet<ImportJobListResponse>(IMPORT_URL)
    return response
  },

  getJob: async (id: string): Promise<ImportJob> => {
    return apiGet<ImportJob>(`${IMPORT_URL}/${id}`)
  },

  createJob: async (
    type: ImportType,
    file: File,
    _columnMapping?: Record<string, string>
  ): Promise<CreateImportResponse> => {
    const formData = new FormData()
    formData.append('type', type)
    formData.append('file', file)

    // Large files need more time for parsing + validation (up to 2 minutes)
    const response = await api.post<CreateImportResponse>(IMPORT_URL, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000, // 120 seconds for large imports
    })
    return response.data
  },

  getErrors: async (jobId: string): Promise<ImportErrorsResponse> => {
    const response = await apiGet<ImportErrorsResponse>(
      `${IMPORT_URL}/${jobId}/errors`
    )
    return response
  },

  getPreview: async (jobId: string): Promise<ImportPreview> => {
    return apiGet<ImportPreview>(`${IMPORT_URL}/${jobId}/preview`)
  },

  executeImport: async (jobId: string): Promise<ImportJob> => {
    return apiPost<ImportJob>(`${IMPORT_URL}/${jobId}/execute`)
  },

  deleteJob: async (jobId: string): Promise<void> => {
    await api.delete(`${IMPORT_URL}/${jobId}`)
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
    // Returns URL for direct download
    return `/api/v1${WIZARD_URL}/template/${type}`
  },

  getMigrationStatus: async (): Promise<MigrationStatus> => {
    return apiGet<MigrationStatus>(`${WIZARD_URL}/status`)
  },
}
