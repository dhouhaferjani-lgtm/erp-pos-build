import { apiGet } from '@/lib/api';
import type { FullReceiptResponse } from '@/types/receipt';

/**
 * Phase 1 Task 27 Pass 1 (spec §14.3 chokepoint disposition):
 * `createReceipt()` (`POST /pos/receipts`) and `processReceiptPayments()`
 * (`POST /pos/receipts/{id}/payments`) — the new-sale server-authoring
 * callers — have been DELETED. The device authors every new sale locally
 * (`createOfflineReceipt()`); the sync service ships the sealed receipt
 * via the offline-sync pipeline.
 *
 * Only read-only methods remain.
 */
export async function fetchReceipt(id: string): Promise<FullReceiptResponse> {
  return apiGet<FullReceiptResponse>(`/pos/receipts/${id}`);
}
