import { apiGet, apiPost } from '@/lib/api';
import type {
  CreateReceiptRequest,
  CreateReceiptResponse,
  FullReceiptResponse,
  ProcessReceiptPaymentsRequest,
  ProcessReceiptPaymentsResponse,
} from '@/types/receipt';

export async function fetchReceipt(id: string): Promise<FullReceiptResponse> {
  return apiGet<FullReceiptResponse>(`/pos/receipts/${id}`);
}

export async function createReceipt(
  data: CreateReceiptRequest,
): Promise<CreateReceiptResponse> {
  return apiPost<CreateReceiptResponse>('/pos/receipts', data);
}

export async function processReceiptPayments(
  receiptId: string,
  data: ProcessReceiptPaymentsRequest,
): Promise<ProcessReceiptPaymentsResponse> {
  return apiPost<ProcessReceiptPaymentsResponse>(
    `/pos/receipts/${receiptId}/payments`,
    data,
  );
}
