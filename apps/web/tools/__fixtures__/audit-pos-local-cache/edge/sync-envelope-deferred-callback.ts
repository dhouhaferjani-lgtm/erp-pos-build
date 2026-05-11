// Fixture (Codex round-1 finding #1, regression): the handler captures
// `envelope.tenant_id` inside a deferred callback that is never executed
// before the payload is read. A read-into-callback is NOT a synchronous
// guard — the scanner MUST flag this as
// pattern_type = sync_envelope_without_tenant_check.

interface SyncEnvelope {
  tenant_id: string;
  company_id: string;
  payload: unknown;
}

declare function setupValidator(check: () => string): void;

export function handleSyncEnvelope(envelope: SyncEnvelope): unknown {
  setupValidator(() => envelope.tenant_id);
  const payload = envelope.payload;
  return payload;
}
