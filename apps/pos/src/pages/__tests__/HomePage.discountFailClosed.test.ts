import { describe, expect, it } from 'vitest';
import { resolveDiscountAccess } from '@/lib/discountPermissions';
import type { Operator } from '@/stores/operatorStore';

function makeOperator(overrides: Partial<Operator> = {}): Operator {
  return {
    id: 'op-1',
    name: 'Cashier',
    email: 'cashier@example.com',
    roles: ['cashier'],
    permissions: ['pos.sell'],
    can_discount: true,
    max_discount_percent: null,
    discount_permissions_status: 'fresh',
    ...overrides,
  };
}

describe('HomePage discount fail-closed resolution', () => {
  it('disables discounts when no operator is loaded', () => {
    expect(resolveDiscountAccess(null, 10, true, true)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.requiresOperator',
    });
  });

  it('requires manager approval when the operator cannot discount', () => {
    expect(resolveDiscountAccess(makeOperator({ can_discount: false }), 10, true, false)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: null,
    });
  });

  it('hard-disables discounts when the terminal denied them', () => {
    expect(resolveDiscountAccess(makeOperator({
      can_discount: false,
      discount_permissions_status: 'terminal_denied',
    }), 10, true, false)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    });
  });

  it('hard-disables discounts when permission status is missing', () => {
    const operator = makeOperator();
    delete operator.discount_permissions_status;

    expect(resolveDiscountAccess(operator, 10, true, true)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    });
  });

  it('uses the terminal limit when the operator has no individual cap', () => {
    expect(resolveDiscountAccess(makeOperator({ max_discount_percent: null }), 10, true, true)).toEqual({
      canDiscount: true,
      maxDiscountPercent: 10,
      disabledReason: null,
    });
  });

  it('uses the lower operator limit when both operator and terminal limits exist', () => {
    expect(resolveDiscountAccess(makeOperator({ max_discount_percent: 5 }), 10, true, true)).toEqual({
      canDiscount: true,
      maxDiscountPercent: 5,
      disabledReason: null,
    });
  });

  it('disables discounts when the terminal limit is zero', () => {
    expect(resolveDiscountAccess(makeOperator(), 0, true, true)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    });
  });

  it('hard-disables discounts when the operator needs manager approval but the terminal limit is zero', () => {
    expect(resolveDiscountAccess(makeOperator({ can_discount: false }), 0, true, false)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    });
  });

  it('disables discounts when cached permissions are stale', () => {
    expect(resolveDiscountAccess(makeOperator({ discount_permissions_status: 'stale' }), 10, true, true)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsStale',
    });
  });

  it('hard-disables discounts when that discount type is disabled on the terminal', () => {
    expect(resolveDiscountAccess(makeOperator(), 10, false, false)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    });
  });

  it('hard-disables discounts when the fresh response denies that discount type', () => {
    expect(resolveDiscountAccess(makeOperator(), 10, true, false)).toEqual({
      canDiscount: false,
      maxDiscountPercent: 0,
      disabledReason: 'discount.permissionsUnavailable',
    });
  });
});
