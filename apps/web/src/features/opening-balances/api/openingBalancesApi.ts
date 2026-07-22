import { api, apiGet, apiPost, apiDelete } from '../../../lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'
import type {
  OpeningBalanceBatch,
  OpeningBalanceImportRow,
  OpeningBatchTypeInfo,
  OpeningBatchStatusResponse,
  CreateBatchPayload,
  ValidationResult,
  PostPreview,
  PostResult,
  ImportRowPayload,
} from '../types'

const BASE_URL = '/companies'

/**
 * API client for Opening Balance Batch operations
 */
export const openingBalancesApi = {
  /**
   * Get available batch types
   */
  async getTypes(): Promise<OpeningBatchTypeInfo[]> {
    return apiGet<OpeningBatchTypeInfo[]>('/opening-batches/types')
  },

  /**
   * Get status for all batch types for a company
   */
  async getStatus(companyId: string): Promise<OpeningBatchStatusResponse> {
    return apiGet<OpeningBatchStatusResponse>(`${BASE_URL}/${companyId}/opening-batches/status`)
  },

  /**
   * List all batches for a company
   */
  async list(companyId: string): Promise<OpeningBalanceBatch[]> {
    return apiGet<OpeningBalanceBatch[]>(`${BASE_URL}/${companyId}/opening-batches`)
  },

  /**
   * Get a single batch by ID
   */
  async get(companyId: string, batchId: string): Promise<OpeningBalanceBatch> {
    return apiGet<OpeningBalanceBatch>(`${BASE_URL}/${companyId}/opening-batches/${batchId}`)
  },

  /**
   * Create a new batch
   */
  async create(companyId: string, payload: CreateBatchPayload): Promise<OpeningBalanceBatch> {
    return apiPost<OpeningBalanceBatch>(`${BASE_URL}/${companyId}/opening-batches`, payload)
  },

  /**
   * Delete a draft batch
   */
  async delete(companyId: string, batchId: string): Promise<void> {
    await apiDelete<void>(`${BASE_URL}/${companyId}/opening-batches/${batchId}`)
  },

  /**
   * Get import rows for a batch (paginated)
   */
  async getRows(
    companyId: string,
    batchId: string,
    params?: { page?: number; per_page?: number; status?: string }
  ): Promise<{
    data: OpeningBalanceImportRow[]
    meta: OffsetPaginationMeta
  }> {
    const response = await api.get(`${BASE_URL}/${companyId}/opening-batches/${batchId}/rows`, {
      params,
    })
    return response.data
  },

  /**
   * Import rows from JSON data
   */
  async importRows(
    companyId: string,
    batchId: string,
    payload: ImportRowPayload
  ): Promise<{ imported: number; skipped: number }> {
    return apiPost<{ imported: number; skipped: number }>(
      `${BASE_URL}/${companyId}/opening-batches/${batchId}/import`,
      payload
    )
  },

  /**
   * Validate a batch
   */
  async validate(companyId: string, batchId: string): Promise<ValidationResult> {
    return apiPost<ValidationResult>(`${BASE_URL}/${companyId}/opening-batches/${batchId}/validate`)
  },

  /**
   * Get post preview for a batch
   */
  async preview(companyId: string, batchId: string): Promise<PostPreview> {
    return apiGet<PostPreview>(`${BASE_URL}/${companyId}/opening-batches/${batchId}/preview`)
  },

  /**
   * Post a batch (create journal entries/documents)
   */
  async post(companyId: string, batchId: string): Promise<PostResult> {
    return apiPost<PostResult>(`${BASE_URL}/${companyId}/opening-batches/${batchId}/post`)
  },

  /**
   * Lock a batch (make it immutable)
   */
  async lock(companyId: string, batchId: string): Promise<OpeningBalanceBatch> {
    return apiPost<OpeningBalanceBatch>(`${BASE_URL}/${companyId}/opening-batches/${batchId}/lock`)
  },
}
