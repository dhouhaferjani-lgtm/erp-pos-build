// Fixture (Codex round-1 finding #1, regression): the handler reads
// `envelope.tenant_id` into a local variable but never compares it to
// the active auth context. A bare read is NOT a guard — the scanner
// MUST flag this as pattern_type = sync_envelope_without_tenant_check.

interface SyncEnvelope {
  tenant_id: string;
  company_id: string;
  payload: unknown;
}

export function handleSyncEnvelope(envelope: SyncEnvelope): {
  tenantId: string;
  payload: unknown;
} {
  const tenantId = envelope.tenant_id;
  const payload = envelope.payload;
  return { tenantId, payload };
}
