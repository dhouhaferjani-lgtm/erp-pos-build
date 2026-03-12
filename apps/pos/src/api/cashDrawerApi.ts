import { apiGet, apiPost } from '@/lib/api';

export async function depositCash(data: { amount: string; reason: string }): Promise<void> {
  return apiPost<void>('/pos/cash-drawer/deposit', data);
}

export async function payoutCash(data: { amount: string; reason: string }): Promise<void> {
  return apiPost<void>('/pos/cash-drawer/payout', data);
}

export async function fetchDrawerBalance(shiftId: string): Promise<{ balance: string }> {
  return apiGet<{ balance: string }>(`/pos/cash-drawer/${shiftId}/balance`);
}
