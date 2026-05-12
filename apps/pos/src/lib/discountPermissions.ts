import type { Operator } from '@/stores/operatorStore';

export const DISCOUNT_PERMISSION_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

export type DiscountPermissionStatus = 'fresh' | 'terminal_denied' | 'unavailable' | 'stale';

export interface DiscountAccessState {
  canDiscount: boolean;
  maxDiscountPercent: number;
  disabledReason: string | null;
}

export function resolveDiscountAccess(
  operator: Pick<Operator, 'can_discount' | 'max_discount_percent' | 'discount_permissions_status'> | null,
  terminalMaxDiscountPercent: number | null | undefined,
  terminalAllowsDiscounts: boolean | null | undefined,
  operatorAllowsDiscountType: boolean | null | undefined,
): DiscountAccessState {
  if (operator === null) {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.requiresOperator',
    };
  }

  if (operator.discount_permissions_status === 'terminal_denied') {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    };
  }

  if (operator.discount_permissions_status === 'stale') {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsStale',
    };
  }

  if (operator.discount_permissions_status !== 'fresh') {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    };
  }

  if (
    terminalAllowsDiscounts !== true
    || typeof terminalMaxDiscountPercent !== 'number'
    || terminalMaxDiscountPercent <= 0
  ) {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    };
  }

  if (!operator.can_discount) {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: null,
    };
  }

  if (operatorAllowsDiscountType !== true) {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    };
  }

  const operatorLimit = operator.max_discount_percent ?? terminalMaxDiscountPercent;
  const effectiveLimit = Math.min(operatorLimit, terminalMaxDiscountPercent);

  if (effectiveLimit <= 0) {
    return {
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    };
  }

  return {
    canDiscount: true,
    maxDiscountPercent: effectiveLimit,
    disabledReason: null,
  };
}

export function resolveCachedDiscountStatus(
  fetchedAt: string | null | undefined,
  nowMs: number = Date.now(),
): DiscountPermissionStatus {
  if (!fetchedAt) {
    return 'unavailable';
  }

  const fetchedAtMs = Date.parse(fetchedAt);
  if (!Number.isFinite(fetchedAtMs)) {
    return 'unavailable';
  }

  return nowMs - fetchedAtMs > DISCOUNT_PERMISSION_CACHE_TTL_MS ? 'stale' : 'fresh';
}
