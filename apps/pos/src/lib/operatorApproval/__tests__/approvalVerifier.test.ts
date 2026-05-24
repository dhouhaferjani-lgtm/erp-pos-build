import { describe, expect, it, vi } from 'vitest';
import { verifyOfflineApprovalPin } from '../approvalVerifier';
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
