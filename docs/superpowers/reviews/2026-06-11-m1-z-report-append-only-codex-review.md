# Adversarial Review: M1 — pos_z_reports append-only trigger

> Codex review of commit "feat(pos): M1 — pos_z_reports append-only trigger
> (transition-aware, PG-verified)" on `feat/zreport-launch-blockers`.
> Codex's sandbox blocked its file write, so this file transcribes the verdict
> and findings it returned inline (two independent Codex passes converged on
> the same findings).

## Verdict
REQUEST-CHANGES

## Confidence
88%

## Findings

### [P1] Legacy→canonical upgrade branch is too permissive (same-statement tamper)
The trigger allows any UPDATE on a legacy row (`OLD.fiscal_event_id IS NULL`)
as long as `NEW.fiscal_event_id IS NOT NULL`. A direct SQL UPDATE can therefore
rewrite the fiscal mirror fields (`fiscal_hash`, `z_number`, `report_data`,
`grand_totals`, `canonical_bytes`, …) in the same statement that stamps an
existing `fiscal_event_id`. The FK to `fiscal_events` only proves the event
EXISTS — it does not prove the event is a verified `Z_REPORT` for this
terminal, nor that the mirror fields being written actually match the event's
authoritative values (`current_hash`, `canonical_bytes`, payload `z_number`).

**Fix:** in the upgrade branch, `SELECT` the stamped event and require:
`event_type = 'Z_REPORT'`, `integrity_status = 'verified'`, terminal match,
`NEW.fiscal_hash = current_hash`, `NEW.canonical_bytes = canonical_bytes`,
`NEW.z_number = (payload->>'z_number')::int`, and the payload-derived mirrors
(`report_data->'canonical_z_report'`, `grand_totals`) to equal the event
payload.

### [P2] Test coverage gaps
The suite never exercises the same-statement tamper path (stamping a valid
`fiscal_event_id` while writing mismatched mirror fields), and the identity-
immutability coverage tests only `shift_id` — `id` and `terminal_id` are
asserted by the trigger but not pinned by a test.

## Clean
- Writer inventory confirmed complete: no missed runtime UPDATE/DELETE writer
  of `pos_z_reports` (model writes, `DB::table`, raw SQL, seeders, console
  commands, `FiscalSchemaCutoverService`, jobs all swept).
- Tenant-migration placement + pgsql-only guard match sibling trigger
  migrations; `CREATE OR REPLACE` + `DROP TRIGGER IF EXISTS` re-run-safe;
  `down()` clean (no other object depends on the function).
- ci.yml `--filter` addition is regression-safe.

## Resolution (same session)
Both findings implemented test-first; see the follow-up commit
"fix(pos): M1 — validate the canonical upgrade against the stamped fiscal
event (Codex P1+P2)". Residual accepted risk documented in the migration:
none — all mirror fields written during the upgrade are now pinned to the
stamped event's authoritative values.
