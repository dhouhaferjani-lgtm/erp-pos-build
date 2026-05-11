// Fixture: a sync envelope handler that validates `envelope.tenant_id`
// against the active auth context BEFORE accessing `payload`. The scanner
// MUST NOT flag this — the tenant identity check guards the payload.

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
  if (envelope.tenant_id !== currentAuthContext.tenantId) {
    throw new Error('Sync envelope tenant mismatch');
  }
  const { payload } = envelope;
  return payload;
}
