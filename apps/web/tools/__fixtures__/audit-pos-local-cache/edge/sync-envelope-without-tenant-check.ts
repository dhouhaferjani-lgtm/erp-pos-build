// Fixture: a sync envelope handler that destructures `envelope.payload`
// (or accesses it) WITHOUT first validating `envelope.tenant_id` against
// the active auth context. The scanner MUST flag this as
// pattern_type = sync_envelope_without_tenant_check.

interface SyncEnvelope {
  tenant_id: string;
  company_id: string;
  payload: unknown;
}

export function handleSyncEnvelope(envelope: SyncEnvelope): unknown {
  const { payload } = envelope;
  return payload;
}
