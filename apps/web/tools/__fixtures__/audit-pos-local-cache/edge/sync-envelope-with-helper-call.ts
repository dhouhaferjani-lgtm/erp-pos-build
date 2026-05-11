// Fixture (Codex round-1 finding #1): the handler calls an allowlisted
// synchronous validator helper (`assertEnvelopeTenant`) at the top level
// before reading the payload. The scanner MUST NOT flag this — the
// allowlisted helper is treated as a guard equivalent to an inline
// `if`/throw comparison.

interface SyncEnvelope {
  tenant_id: string;
  company_id: string;
  payload: unknown;
}

declare function assertEnvelopeTenant(envelope: SyncEnvelope): void;

export function handleSyncEnvelope(envelope: SyncEnvelope): unknown {
  assertEnvelopeTenant(envelope);
  const { payload } = envelope;
  return payload;
}
