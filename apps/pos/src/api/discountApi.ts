import { apiGet } from '@/lib/api';

export interface DiscountPermissions {
  canDiscount: boolean;
  userCanDiscount: boolean;
  userMaxDiscountPercent: number | null;
  terminalAllowsDiscounts: boolean;
  canApplyLineDiscounts: boolean;
  canApplyTransactionDiscounts: boolean;
  maxDiscountPercent: number;
  requiresReason: boolean;
}

export async function fetchDiscountPermissions(
  terminalCode: string,
  operatorId?: string,
): Promise<DiscountPermissions> {
  return apiGet<DiscountPermissions>('/pos/discount-permissions', {
    terminal_code: terminalCode,
    operator_id: operatorId,
  });
}
