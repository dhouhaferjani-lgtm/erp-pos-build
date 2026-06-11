# M1 — `pos_z_reports` append-only trigger + hash coverage (spec)

> Launch audit (2026-06-09) item **M1**, MEDIUM. NOT shipped blind: this changes a PG trigger on
> the signed Z mirror and (part 2) the signed Z hash bytes — both require a real-PG run + (part 2)
> device/server coordination. Spec'd here with the full transition analysis so it can be
> implemented + verified in a PG-equipped session.

## Part 1 — append-only trigger (PG, tenant migration)

`pos_z_reports` currently has no immutability trigger (unlike `pos_receipts`,
`voucher_ledger`, `fiscal_events`). A Z mirror row can be silently UPDATE/DELETEd.

**It is NOT a block-all trigger** — there ARE legitimate post-insert updates that must be allowed:

| Site | Transition | Must allow |
|---|---|---|
| `ReportGenerationService.php:312-322` | `create([...])` then sets `fiscal_hash` and `save()` | first write of `fiscal_hash` on a row whose `fiscal_hash` was NULL |
| `ZReportProjection.php:72-83` | `$existing->fill($row); $existing->save()` (idempotent re-projection) | first write of `fiscal_event_id` (NULL → value); guarded so it cannot change to a *different* id |

Trigger design (mirror `prevent_receipt_modification()` in
`2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php`):

```sql
CREATE OR REPLACE FUNCTION prevent_z_report_modification() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'Cannot delete fiscal Z-report %', OLD.z_number
      USING ERRCODE = 'integrity_constraint_violation';
  END IF;
  IF TG_OP = 'UPDATE' THEN
    -- Allow the one-time fiscal_hash seal (NULL -> value), nothing else changing the sealed core.
    -- Allow the one-time fiscal_event_id linkage (NULL -> value).
    -- Once fiscal_hash IS NOT NULL, reject any change to: fiscal_hash, z_number, shift_id,
    -- previous_z_hash, report_data, grand_totals, receipt_snapshots, generated_at.
    IF OLD.fiscal_hash IS NOT NULL AND (
         NEW.fiscal_hash IS DISTINCT FROM OLD.fiscal_hash OR
         NEW.z_number   IS DISTINCT FROM OLD.z_number OR
         NEW.shift_id   IS DISTINCT FROM OLD.shift_id OR
         NEW.report_data::text   IS DISTINCT FROM OLD.report_data::text OR
         NEW.grand_totals::text  IS DISTINCT FROM OLD.grand_totals::text OR
         NEW.receipt_snapshots::text IS DISTINCT FROM OLD.receipt_snapshots::text) THEN
      RAISE EXCEPTION 'Z-report % is sealed and cannot be modified', OLD.z_number
        USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    -- fiscal_event_id may go NULL -> value but never value -> different value
    IF OLD.fiscal_event_id IS NOT NULL AND NEW.fiscal_event_id IS DISTINCT FROM OLD.fiscal_event_id THEN
      RAISE EXCEPTION 'Z-report % fiscal_event_id is immutable', OLD.z_number
        USING ERRCODE = 'integrity_constraint_violation';
    END IF;
  END IF;
  RETURN NEW;
END; $$ LANGUAGE plpgsql;

CREATE TRIGGER enforce_z_report_immutability
  BEFORE UPDATE OR DELETE ON pos_z_reports
  FOR EACH ROW EXECUTE FUNCTION prevent_z_report_modification();
```

Migration: `apps/api/database/migrations/tenant/…_create_pos_z_reports_immutability_trigger.php`,
guarded `if (driver !== 'pgsql') return;` (SQLite has no triggers here).

**Verification (PG-only, cannot run on the SQLite suite):** add a Feature test mirroring the
voucher_ledger/receipt immutability tests, run under the `backend-test-pgsql` gate — assert:
delete blocked; sealed-field UPDATE blocked; the two allowed transitions (fiscal_hash NULL→value,
fiscal_event_id NULL→value) succeed; `ReportGenerationService::generateZReport` end-to-end still
seals a Z (regression). Recall the PG-vs-SQLite gotcha: a Blueprint `->unique()` is a CONSTRAINT on
PG / INDEX on SQLite — the SQLite suite cannot catch this trigger at all.

## Part 2 — hash covers receipt_snapshots + grand_totals (signed bytes)

The legacy `pos_z_reports.fiscal_hash` excludes `receipt_snapshots` and `grand_totals` from the
hashed bytes (legacy/coffee-shop exposure). For v3 device-authority terminals the canonical
`Z_REPORT` fiscal event is the real signed record (full canonical bytes), so this is a
**legacy-only** gap; given clean slate (no v2 deployed) it is LOWER priority than Part 1.

If pursued: extend BOTH `ZReportHashService::calculateHash` (server) AND the device
`computeZReportHash` (`apps/pos/src/lib/fiscal/zReportHashService.ts`) to fold the snapshots +
grand_totals into the canonical byte string, byte-for-byte identically. Requires the cross-language
fixture-sync gate (the v3 golden-hashes fixtures) and a fresh golden hash. Owner sign-off on the
new canonical byte layout required (this is the signed Z record).

## Recommendation

Ship **Part 1** (trigger) in a PG-equipped session — it is bounded and high-value (immutability of
the stored Z). Treat **Part 2** as a separate, lower-priority signed-bytes change gated on owner
sign-off, since v3 canonical events already carry the authoritative signed Z.
