import { apiGet } from '@/lib/api';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';

export async function fetchPaymentMethods(): Promise<PaymentMethod[]> {
  return apiGet<PaymentMethod[]>('/payment-methods');
}

export async function fetchPaymentRepositories(): Promise<PaymentRepository[]> {
  return apiGet<PaymentRepository[]>('/payment-repositories');
}
