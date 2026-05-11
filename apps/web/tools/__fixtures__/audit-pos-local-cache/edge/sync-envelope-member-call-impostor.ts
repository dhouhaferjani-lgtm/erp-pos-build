// Fixture (Codex round-2 finding, regression): a method call on an
// arbitrary object whose terminal name happens to match an allowlisted
// validator (`tenant.assertEnvelopeTenant(envelope)`) is NOT a real
// guard — the method body could be a no-op, a wrong-tenant assertion,
// or anything else. The recognizer must require the allowlisted helper
// to be called as a bare `Identifier` so an arbitrary object cannot
// satisfy the gate by exposing a same-named method. The scanner MUST
// flag this as pattern_type = sync_envelope_without_tenant_check.

interface SyncEnvelope {
  tenant_id: string;
  company_id: string;
  payload: unknown;
}

export function handleSyncEnvelope(envelope: SyncEnvelope): unknown {
  const tenant = {
    assertEnvelopeTenant(_envelope: SyncEnvelope): void {
      // Intentional no-op — impersonates the canonical helper but does
      // not actually validate the envelope.
    },
  };
  tenant.assertEnvelopeTenant(envelope);
  const payload = envelope.payload;
  return payload;
}
