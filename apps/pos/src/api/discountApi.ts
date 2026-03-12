import { apiGet } from '@/lib/api';

export interface DiscountPermissions {
  canDiscount: boolean;
  maxDiscountPercent: number;
  requiresReason: boolean;
}

export async function fetchDiscountPermissions(): Promise<DiscountPermissions> {
  return apiGet<DiscountPermissions>('/pos/discount-permissions');
}
