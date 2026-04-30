import { describe, it, expect } from 'vitest';
import {
  mapRefundErrorToUiAction,
  requiresManagerPin,
  type RefundApiError,
} from '../refundConfirmation';

function makeError(code: string, status = 422): RefundApiError {
  return { status, code, apiMessage: `Error: ${code}` };
}

describe('mapRefundErrorToUiAction()', () => {
  it('maps MANAGER_OVERRIDE_REQUIRED → "manager-pin"', () => {
    expect(mapRefundErrorToUiAction(makeError('MANAGER_OVERRIDE_REQUIRED'))).toBe('manager-pin');
  });

  it('maps DAILY_REFUND_CAP_EXCEEDED → "daily-cap"', () => {
    expect(mapRefundErrorToUiAction(makeError('DAILY_REFUND_CAP_EXCEEDED'))).toBe('daily-cap');
  });

  it('maps REFUND_WINDOW_CLOSED → "window-closed"', () => {
    expect(mapRefundErrorToUiAction(makeError('REFUND_WINDOW_CLOSED'))).toBe('window-closed');
  });

  it('maps BUSINESS_ERROR → "generic" (DomainException fallback)', () => {
    expect(mapRefundErrorToUiAction(makeError('BUSINESS_ERROR'))).toBe('generic');
  });

  it('maps unknown codes → "generic"', () => {
    expect(mapRefundErrorToUiAction(makeError('SOME_OTHER_CODE'))).toBe('generic');
  });

  it('maps non-422 status → "generic" regardless of code', () => {
    expect(mapRefundErrorToUiAction(makeError('MANAGER_OVERRIDE_REQUIRED', 500))).toBe('generic');
    expect(mapRefundErrorToUiAction(makeError('MANAGER_OVERRIDE_REQUIRED', 403))).toBe('generic');
    expect(mapRefundErrorToUiAction(makeError('MANAGER_OVERRIDE_REQUIRED', 400))).toBe('generic');
  });

  it('maps 422 with empty code → "generic"', () => {
    expect(mapRefundErrorToUiAction(makeError(''))).toBe('generic');
  });
});

describe('requiresManagerPin()', () => {
  it('returns true for "manager-pin"', () => {
    expect(requiresManagerPin('manager-pin')).toBe(true);
  });

  it('returns true for "daily-cap"', () => {
    expect(requiresManagerPin('daily-cap')).toBe(true);
  });

  it('returns true for "window-closed"', () => {
    expect(requiresManagerPin('window-closed')).toBe(true);
  });

  it('returns false for "generic"', () => {
    expect(requiresManagerPin('generic')).toBe(false);
  });
});
