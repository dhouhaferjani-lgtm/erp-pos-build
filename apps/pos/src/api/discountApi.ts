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
  // The server returns the discount-limit percents as bcmath strings (precision
  // remediation). These are comparison thresholds (Math.min / relational), not
  // stored/displayed money — coerce to number at the boundary so the declared
  // `number` interface is truthful and downstream `a + b` can never concatenate.
  const raw = await apiGet<Record<string, unknown>>('/pos/discount-permissions', {
    terminal_code: terminalCode,
    operator_id: operatorId,
  });
  return {
    ...(raw as unknown as DiscountPermissions),
    userMaxDiscountPercent:
      raw.userMaxDiscountPercent === null || raw.userMaxDiscountPercent === undefined
        ? null
        : Number(raw.userMaxDiscountPercent),
    maxDiscountPercent: Number(raw.maxDiscountPercent ?? 0),
  };
}
