import { api, apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type {
  Batch,
  GetBatchesParams,
  PaginatedBatchesResponse,
  CreateBatchInput,
  UpdateBatchInput,
  RecallBatchInput,
  ExpiringProduct,
  BatchStockByLocation,
  FEFOResult,
  ExpiredBatch,
  GroupedWriteOffPayload,
  GroupedWriteOffResult,
} from '../types'

/**
 * Result returned by the reverse write-off endpoint.
 * POST /api/v1/stock-movements/{movementId}/reverse-write-off
 */
export interface ReverseWriteOffResult {
  reversal_movement_id: string
  original_movement_id: string
}

/**
 * Get paginated list of batches
 */
export async function getBatches(params?: GetBatchesParams): Promise<PaginatedBatchesResponse> {
  const queryParams: Record<string, string> = {}

  if (params?.product_id) {
    queryParams['product_id'] = params.product_id
  }

  if (params?.location_id) {
    queryParams['location_id'] = String(params.location_id)
  }

  if (params?.expiry_status) {
    queryParams['expiry_status'] = params.expiry_status
  }

  if (params?.is_active !== undefined) {
    queryParams['is_active'] = params.is_active ? '1' : '0'
  }

  if (params?.is_recalled !== undefined) {
    queryParams['is_recalled'] = params.is_recalled ? '1' : '0'
  }

  if (params?.expiring_within_days) {
    queryParams['expiring_within_days'] = String(params.expiring_within_days)
  }

  if (params?.search) {
    queryParams['search'] = params.search
  }

  if (params?.per_page) {
    queryParams['per_page'] = String(params.per_page)
  }

  if (params?.cursor) {
    queryParams['cursor'] = params.cursor
  }

  const response = await api.get<PaginatedBatchesResponse>('/batches', { params: queryParams })
  return response.data
}

/**
 * Get a single batch by UUID
 */
export async function getBatch(uuid: string): Promise<Batch> {
  return apiGet<Batch>(`/batches/${uuid}`)
}

/**
 * Create a new batch
 */
export async function createBatch(input: CreateBatchInput): Promise<Batch> {
  return apiPost<Batch>('/batches', input)
}

/**
 * Update an existing batch
 */
export async function updateBatch(uuid: string, input: UpdateBatchInput): Promise<Batch> {
  return apiPatch<Batch>(`/batches/${uuid}`, input)
}

/**
 * Deactivate a batch (soft delete)
 */
export async function deleteBatch(uuid: string): Promise<void> {
  return apiDelete(`/batches/${uuid}`)
}

/**
 * Initiate a batch recall
 */
export async function recallBatch(uuid: string, input: RecallBatchInput): Promise<Batch> {
  return apiPost<Batch>(`/batches/${uuid}/recall`, input)
}

/**
 * Get products expiring soon
 */
export async function getExpiringProducts(daysThreshold: number = 30): Promise<ExpiringProduct[]> {
  return apiGet<ExpiringProduct[]>('/batches/expiring', { days: String(daysThreshold) })
}

/**
 * Get batch stock levels by location
 */
export async function getBatchStock(uuid: string): Promise<BatchStockByLocation[]> {
  return apiGet<BatchStockByLocation[]>(`/batches/${uuid}/stock`)
}

/**
 * Get FEFO batch suggestions for POS
 */
export async function getFEFOSuggestions(
  productId: string,
  locationId: number,
  quantity: number
): Promise<FEFOResult> {
  return apiGet<FEFOResult>(`/pos/products/${productId}/batches`, {
    location_id: String(locationId),
    quantity: String(quantity),
  })
}

/**
 * Reverse a previously posted batch write-off.
 * POST /api/v1/stock-movements/{movementId}/reverse-write-off
 *
 * Restores aggregate + batch stock and posts a reversing journal entry.
 * Returns 409 if the write-off has already been reversed.
 */
export async function reverseWriteOff(movementId: string): Promise<ReverseWriteOffResult> {
  return apiPost<ReverseWriteOffResult>(`/stock-movements/${movementId}/reverse-write-off`)
}

/**
 * Get all batches for a product with stock information
 */
export async function getProductBatches(
  productId: string,
  variantId?: string | null,
): Promise<Batch[]> {
  return apiGet<Batch[]>(
    `/products/${productId}/batch-stock`,
    variantId != null ? { variant_id: variantId } : undefined,
  )
}

/**
 * Get all expired batches that still have available (un-reserved) stock.
 * Intended for the expiry write-off UI.
 *
 * GET /api/v1/batches/expired
 *
 * @param locationId - Optional UUID to scope results to a single storage location.
 */
export async function getExpiredBatches(locationIds?: string[]): Promise<ExpiredBatch[]> {
  return apiGet<ExpiredBatch[]>(
    '/batches/expired',
    locationIds && locationIds.length > 0 ? { location_ids: locationIds } : undefined,
  )
}

/**
 * Submit a grouped (multi-lot) write-off.
 *
 * POST /api/v1/batches/write-off-grouped
 *
 * Returns HTTP 201 on first application and HTTP 200 on idempotent replay
 * (same `idempotency_key`, stock not touched again).  The `replayed` flag in
 * the result distinguishes the two cases.
 */
export async function groupedWriteOff(payload: GroupedWriteOffPayload): Promise<GroupedWriteOffResult> {
  return apiPost<GroupedWriteOffResult>('/batches/write-off-grouped', payload)
}
