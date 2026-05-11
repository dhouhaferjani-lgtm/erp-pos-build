// Fixture (Codex round-2 finding, regression): a guard hidden inside a
// class constructor body inside an `if` condition is NOT a synchronous
// guard — the constructor body is never executed by the `if`'s test.
// The recognizer must stop at constructor/class boundaries when checking
// for top-level `envelope.tenant_id` references; otherwise an attacker
// (or accidental refactor) could hide the reference inside a class body
// and bypass the gate. The scanner MUST flag this as
// pattern_type = sync_envelope_without_tenant_check.

interface SyncEnvelope {
  tenant_id: string;
  company_id: string;
  payload: unknown;
}

export function handleSyncEnvelope(envelope: SyncEnvelope): unknown {
  if (
    class Hidden {
      constructor() {
        void envelope.tenant_id;
      }
    }
  ) {
    // No-op — the class expression is truthy but its constructor body
    // never runs.
  }
  const payload = envelope.payload;
  return payload;
}
