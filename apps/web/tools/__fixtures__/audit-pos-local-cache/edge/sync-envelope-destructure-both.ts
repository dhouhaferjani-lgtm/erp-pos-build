// Fixture (Codex round-1 finding #1, regression): the handler destructures
// `tenant_id` and `payload` in the SAME statement. The tenant_id reference
// cannot retroactively guard the payload access because both happen
// simultaneously. The scanner MUST flag this as
// pattern_type = sync_envelope_without_tenant_check.
//
// Even though the handler then validates `tenant_id`, the payload was
// already read off the envelope before the comparison ran.

interface SyncEnvelope {
  tenant_id: string;
  company_id: string;
  payload: unknown;
}

interface AuthContext {
  tenantId: string;
}

declare const currentAuthContext: AuthContext;

export function handleSyncEnvelope(envelope: SyncEnvelope): unknown {
  const { tenant_id, payload } = envelope;
  if (tenant_id !== currentAuthContext.tenantId) {
    throw new Error('Sync envelope tenant mismatch');
  }
  return payload;
}
