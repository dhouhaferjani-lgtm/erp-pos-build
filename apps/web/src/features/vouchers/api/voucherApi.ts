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
  return apiGet<VoucherDetail>(`/vouchers/${id}`)
}

// ─── Issue Goodwill ───────────────────────────────────────────────────────────

export async function issueGoodwill(payload: IssueGoodwillPayload): Promise<Voucher> {
  return apiPost<Voucher>('/vouchers/issue-goodwill', payload)
}

// ─── Void ─────────────────────────────────────────────────────────────────────

export async function voidVoucher(id: string, payload: VoidVoucherPayload): Promise<Voucher> {
  return apiPost<Voucher>(`/vouchers/${id}/void`, payload)
}

// ─── Transfer ─────────────────────────────────────────────────────────────────

export async function transferVoucher(id: string, payload: TransferVoucherPayload): Promise<Voucher> {
  return apiPost<Voucher>(`/vouchers/${id}/transfer`, payload)
}

// ─── Extend Expiry ────────────────────────────────────────────────────────────

export async function extendExpiry(id: string, payload: ExtendExpiryPayload): Promise<Voucher> {
  return apiPost<Voucher>(`/vouchers/${id}/extend-expiry`, payload)
}

// ─── Reservation settings (voucher-relevant fields) ──────────────────────────

export async function getVoucherReservationSettings(companyId: string): Promise<VoucherReservationSettings> {
  return apiGet<VoucherReservationSettings>(`/companies/${companyId}/reservation-settings`)
}
