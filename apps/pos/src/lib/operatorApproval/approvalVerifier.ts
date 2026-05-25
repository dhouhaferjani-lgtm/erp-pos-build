import type { CachedOperator } from '@/lib/db/repositories/operatorPinRepository';

export type ApprovalScope =
  | 'close_shift_variance'
  | 'credit_limit_override'
  | 'account_status_override'
  | 'discount_limit_override'
  | 'tender_tolerance_override'
  | 'void_or_return_override'
  | 'cash_drawer_control';

export type OfflineApprovalPinResult =
  | { ok: true; operatorId: string }
  | { ok: false; code: 'scope_mismatch' | 'invalid_pin' | 'server_quarantined' };

export async function verifyOfflineApprovalPin(input: {
  operator: CachedOperator;
  pin: string;
  tenantId: string;
  companyId: string;
  terminalId: string;
  approvalScope: ApprovalScope;
  bcryptCheck: (pin: string, hash: string) => boolean | Promise<boolean>;
}): Promise<OfflineApprovalPinResult> {
  if (input.operator.approval_mirror_status === 'server_quarantined') {
    return { ok: false, code: 'server_quarantined' };
  }

  if (
    input.operator.tenant_id !== input.tenantId ||
    !input.operator.company_ids?.includes(input.companyId) ||
    !input.operator.terminal_ids?.includes(input.terminalId) ||
    !input.operator.approval_scopes?.includes(input.approvalScope)
  ) {
    return { ok: false, code: 'scope_mismatch' };
  }

  const valid = await input.bcryptCheck(input.pin, input.operator.pin_hash);
  if (!valid) {
    return { ok: false, code: 'invalid_pin' };
  }

  return { ok: true, operatorId: input.operator.id };
}
