import { apiGet } from '@/lib/api';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';

// TODO(go-live-followup): server-side endpoint reconciliation between
//   `/payment-methods` (canonical, used here) and `/treasury/payment-methods`
//   (legacy, still mounted). T0.5 PR #86 aligned the client; the server
//   still serves both routes against the same controller. Choose one,
//   redirect or 410-Gone the other, and drop the alias from
//   apps/api/routes/api.php. See
//   docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md
//   §"Out of scope".
export async function fetchPaymentMethods(): Promise<PaymentMethod[]> {
  return apiGet<PaymentMethod[]>('/payment-methods');
}

export async function fetchPaymentRepositories(): Promise<PaymentRepository[]> {
  return apiGet<PaymentRepository[]>('/payment-repositories');
}
