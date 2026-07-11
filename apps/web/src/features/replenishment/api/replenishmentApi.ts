import { api, apiPost } from '@/lib/api'
import type { CaptureReplenishmentInput, CreatePoActionInput, CreateTransferActionInput, OpenReplenishmentListResponse, RejectActionInput, ReplenishmentHistoryResponse, ReplenishmentLine, ReplenishmentListFilters } from '../types'

const BASE = '/replenishment-requests'
const params = (filters: ReplenishmentListFilters) => filters

export const replenishmentApi = {
  open: async (filters: Omit<ReplenishmentListFilters, 'status'> = {}): Promise<OpenReplenishmentListResponse> =>
    (await api.get<OpenReplenishmentListResponse>(BASE, { params: params({ ...filters, status: 'open' }) })).data,
  history: async (filters: ReplenishmentListFilters): Promise<ReplenishmentHistoryResponse> =>
    (await api.get<ReplenishmentHistoryResponse>(BASE, { params: params(filters) })).data,
  capture: (input: CaptureReplenishmentInput) => apiPost<ReplenishmentLine>(BASE, input),
  cancel: (id: string) => apiPost<ReplenishmentLine>(`${BASE}/${id}/cancel`, {}),
  createTransfer: (input: CreateTransferActionInput) => apiPost<{ transfer_ids: string[] }>(`${BASE}/actions/create-transfer`, input),
  createPo: (input: CreatePoActionInput) => apiPost<{ document_id: string }>(`${BASE}/actions/create-po`, input),
  reject: (input: RejectActionInput) => apiPost<{ request_ids: string[] }>(`${BASE}/actions/reject`, input),
}
