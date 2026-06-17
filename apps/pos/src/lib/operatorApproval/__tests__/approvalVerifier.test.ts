import { describe, expect, it, vi } from 'vitest';
import {
  verifyOfflineApprovalPin,
  isApprovalCacheFresh,
  APPROVAL_CACHE_MAX_AGE_MS,
} from '../approvalVerifier';
import type { CachedOperator } from '@/lib/db/repositories/operatorPinRepository';

function operator(overrides: Partial<CachedOperator> = {}): CachedOperator {
  return {
    id: 'supervisor-1',
    name: 'Supervisor',
    email: 'supervisor@example.test',
    pin_hash: '$2a$10$known',
    roles: ['manager'],
    permissions: ['pos.close_shift_with_variance'],
    can_discount: true,
    max_discount_percent: 50,
    discount_permissions_fetched_at: null,
    discount_permissions_terminal_code: null,
    discount_permissions_status: 'fresh',
    tenant_id: 'tenant-1',
    company_ids: ['company-1'],
    terminal_ids: ['terminal-1'],
    approval_scopes: ['close_shift_variance'],
    approval_scope_permissions_fetched_at: '2026-05-22T10:00:00.000Z',
    ...overrides,
  };
}

describe('verifyOfflineApprovalPin', () => {
  it('rejects tenant/company/terminal/scope mismatch before bcrypt', async () => {
    const bcryptCheck = vi.fn().mockResolvedValue(true);

    await expect(verifyOfflineApprovalPin({
      operator: operator({ company_ids: ['company-2'] }),
      pin: '1234',
      tenantId: 'tenant-1',
      companyId: 'company-1',
      terminalId: 'terminal-1',
      approvalScope: 'close_shift_variance',
      bcryptCheck,
    })).resolves.toEqual({ ok: false, code: 'scope_mismatch' });

    expect(bcryptCheck).not.toHaveBeenCalled();
  });

  it('verifies the pin only after scoped approval metadata matches', async () => {
    const bcryptCheck = vi.fn().mockResolvedValue(true);

    await expect(verifyOfflineApprovalPin({
      operator: operator(),
      pin: '1234',
      tenantId: 'tenant-1',
      companyId: 'company-1',
      terminalId: 'terminal-1',
      approvalScope: 'close_shift_variance',
      bcryptCheck,
    })).resolves.toEqual({ ok: true, operatorId: 'supervisor-1' });

    expect(bcryptCheck).toHaveBeenCalledWith('1234', '$2a$10$known');
  });
});

describe('isApprovalCacheFresh (FU-1b TTL)', () => {
  const T0 = Date.parse('2026-06-15T12:00:00.000Z');

  it('is fresh when the cache was fetched within the TTL', () => {
    const fetchedAt = new Date(T0 - (APPROVAL_CACHE_MAX_AGE_MS - 60_000)).toISOString();
    expect(isApprovalCacheFresh(fetchedAt, T0)).toBe(true);
  });

  it('is stale once the cache is older than the TTL', () => {
    const fetchedAt = new Date(T0 - (APPROVAL_CACHE_MAX_AGE_MS + 60_000)).toISOString();
    expect(isApprovalCacheFresh(fetchedAt, T0)).toBe(false);
  });

  it('treats exactly-at-the-boundary as fresh', () => {
    const fetchedAt = new Date(T0 - APPROVAL_CACHE_MAX_AGE_MS).toISOString();
    expect(isApprovalCacheFresh(fetchedAt, T0)).toBe(true);
  });

  it('fails closed on a null/missing fetch time', () => {
    expect(isApprovalCacheFresh(null, T0)).toBe(false);
    expect(isApprovalCacheFresh(undefined, T0)).toBe(false);
  });

  it('fails closed on an unparseable fetch time', () => {
    expect(isApprovalCacheFresh('not-a-date', T0)).toBe(false);
  });

  it('treats a modestly future fetch time (server/clock skew) as fresh', () => {
    const fetchedAt = new Date(T0 + 60_000).toISOString();
    expect(isApprovalCacheFresh(fetchedAt, T0)).toBe(true);
  });

  it('fails closed on an implausibly future fetch time (gross skew / tampering)', () => {
    const fetchedAt = new Date(T0 + 365 * 24 * 60 * 60 * 1000).toISOString();
    expect(isApprovalCacheFresh(fetchedAt, T0)).toBe(false);
  });

  it('honours a custom maxAgeMs override', () => {
    const fetchedAt = new Date(T0 - 2 * 60_000).toISOString();
    expect(isApprovalCacheFresh(fetchedAt, T0, 60_000)).toBe(false);
    expect(isApprovalCacheFresh(fetchedAt, T0, 5 * 60_000)).toBe(true);
  });
});
