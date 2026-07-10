import { apiGetRaw, apiPost, type ApiRequestOptions } from '@/lib/api';

export interface ReplenishmentPushBody {
  client_request_uuid: string;
  terminal_id: string;
  product_id: string;
  variant_id?: string | null;
  requested_qty?: string | null;
  note?: string | null;
}

export interface ServerReplenishmentRow {
  id: string;
  product_id: string;
  variant_id: string | null;
  status: string;
  requested_qty: string | null;
  request_count: number;
  last_requested_at: string;
}

export async function pushReplenishmentRequest(
  body: ReplenishmentPushBody,
  opts?: ApiRequestOptions,
): Promise<ServerReplenishmentRow> {
  return apiPost<ServerReplenishmentRow>('/pos/replenishment-requests', body, opts);
}

export async function fetchOpenReplenishment(
  terminalId: string,
  opts?: ApiRequestOptions,
): Promise<{ data: ServerReplenishmentRow[]; as_of: string; truncated: boolean }> {
  return apiGetRaw<{ data: ServerReplenishmentRow[]; as_of: string; truncated: boolean }>(
    '/pos/replenishment-requests',
    { terminal_id: terminalId },
    opts,
  );
}
