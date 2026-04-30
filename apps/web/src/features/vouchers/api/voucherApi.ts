import { api, apiGet, apiPost } from '@/lib/api'
import type {
  VoucherListParams,
  VoucherListResponse,
  VoucherDetail,
  IssueGoodwillPayload,
  VoidVoucherPayload,
  TransferVoucherPayload,
  ExtendExpiryPayload,
  Voucher,
  VoucherReservationSettings,
} from '../types/voucher'

// ─── List (paginated — use api.get to preserve meta) ─────────────────────────

export async function listVouchers(params: VoucherListParams = {}): Promise<VoucherListResponse> {
  const searchParams = new URLSearchParams()
  if (params.page) searchParams.set('page', String(params.page))
  if (params.per_page) searchParams.set('per_page', String(params.per_page))
  if (params.source) searchParams.set('source', params.source)
  if (params.status) searchParams.set('status', params.status)
  if (params.partner_id) searchParams.set('partner_id', params.partner_id)
  if (params.search) searchParams.set('search', params.search)

  const query = searchParams.toString()
  const response = await api.get<VoucherListResponse>(`/vouchers${query ? `?${query}` : ''}`)
  return response.data
}

// ─── Detail ──────────────────────────────────────────────────────────────────

export async function getVoucher(id: string): Promise<VoucherDetail> {
  interface SingleResponse { data: VoucherDetail }
  const result = await apiGet<SingleResponse>(`/vouchers/${id}`)
  return result.data
}

// ─── Issue Goodwill ───────────────────────────────────────────────────────────

export async function issueGoodwill(payload: IssueGoodwillPayload): Promise<Voucher> {
  interface SingleResponse { data: Voucher }
  const result = await apiPost<SingleResponse>('/vouchers/issue-goodwill', payload)
  return result.data
}

// ─── Void ─────────────────────────────────────────────────────────────────────

export async function voidVoucher(id: string, payload: VoidVoucherPayload): Promise<Voucher> {
  interface SingleResponse { data: Voucher }
  const result = await apiPost<SingleResponse>(`/vouchers/${id}/void`, payload)
  return result.data
}

// ─── Transfer ─────────────────────────────────────────────────────────────────

export async function transferVoucher(id: string, payload: TransferVoucherPayload): Promise<Voucher> {
  interface SingleResponse { data: Voucher }
  const result = await apiPost<SingleResponse>(`/vouchers/${id}/transfer`, payload)
  return result.data
}

// ─── Extend Expiry ────────────────────────────────────────────────────────────

export async function extendExpiry(id: string, payload: ExtendExpiryPayload): Promise<Voucher> {
  interface SingleResponse { data: Voucher }
  const result = await apiPost<SingleResponse>(`/vouchers/${id}/extend-expiry`, payload)
  return result.data
}

// ─── Reservation settings (voucher-relevant fields) ──────────────────────────

export async function getVoucherReservationSettings(companyId: string): Promise<VoucherReservationSettings> {
  interface SettingsResponse { data: VoucherReservationSettings }
  const result = await apiGet<SettingsResponse>(`/companies/${companyId}/reservation-settings`)
  return result.data
}
