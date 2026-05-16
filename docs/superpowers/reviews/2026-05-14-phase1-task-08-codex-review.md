# Task 8 Adversarial Review — 2026-05-14

## Verdict
REJECT — the trigger mostly matches Task 8, but canonical_parse_failure can still be marked verified without the required payload/parsed resume, and the PG-only test is not in the PG merge gate.

## Findings

### [BLOCKER] canonical_parse_failure can bypass the failed→parsed resume gate
**File:** apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:183
**Spec ref:** docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md §7.5:455-456; docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md §Task 24:1848
**Evidence:** The generic `quarantined → verified` branch only checks `NEW.integrity_resolved_at` and `NEW.integrity_resolved_by`:

```sql
ELSIF OLD.integrity_status = 'quarantined' AND NEW.integrity_status = 'verified' THEN
    IF NEW.integrity_resolved_at IS NULL OR NEW.integrity_resolved_by IS NULL THEN
```

That means a row with `OLD.payload_parse_status = 'failed'`, `OLD.payload IS NULL`, and `OLD.integrity_exception_class = 'canonical_parse_failure'` can be updated to `integrity_status = 'verified'` with resolution stamps while leaving `payload_parse_status = 'failed'` and `payload = NULL`. The failed→parsed seven-condition gate at lines 138-147 only runs when `payload_parse_status` changes. Spec §7.5 says canonical_parse_failure resolution is the atomic `payload` write + `payload_parse_status → parsed` flip + projection-row insertion transaction, and Task 24 repeats that the resolver writes payload, flips parse status, flips integrity to verified, and stamps the resolver in one transaction.

**Fix:** In the `quarantined → verified` branch, special-case `OLD.integrity_exception_class = 'canonical_parse_failure'` and require the same resume tuple enforced at lines 140-146: old payload null, new payload non-null, `OLD.payload_parse_status = 'failed'`, `NEW.payload_parse_status = 'parsed'`, both resolution stamps non-null. Reject canonical_parse_failure verification that does not complete the parse resume in the same update.

### [P1] PG-only immutability test is skipped on SQLite and absent from the PG merge gate
**File:** apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:203; .github/workflows/ci.yml:330
**Spec ref:** feedback_audit_ci_gate_check.md PG-only-skipped-tests rule as quoted in the review prompt; local file was not present in this worktree.
**Evidence:** The test class skips every trigger assertion outside PostgreSQL:

```php
if (DB::connection()->getDriverName() !== 'pgsql') {
    $this->markTestSkipped('fiscal_events immutability triggers only exist on PostgreSQL');
}
```

The PG merge gate runs a hard-coded filter:

```bash
php artisan test \
  --filter="VoucherLedgerTest|VoucherLedgerAppendOnlyTest|VoucherSchemaTest|FiscalHardeningE2ETest"
```

`FiscalEventsImmutabilityTest` is not included, and I found no migration-name discovery rule in the available CI files. So the new trigger semantics are skipped by the default SQLite suite and not covered by the PG-backed merge gate.

**Fix:** Add `FiscalEventsImmutabilityTest` to the `backend-test-pgsql` filter, or replace the hard-coded filter with the intended discovery rule and prove that `2026_05_14_100002_create_fiscal_events_immutability.php` / `FiscalEventsImmutabilityTest` are selected.

### [P2] Break-glass runbook falsely says session_replication_role will not bypass user triggers
**File:** apps/api/docs/runbooks/fiscal-events-break-glass.md:95
**Spec ref:** docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md §3.3:217-219
**Evidence:** The runbook tells the DBA to open the privileged transaction with:

```sql
BEGIN; SET LOCAL session_replication_role = 'replica';
```

and then states that this "will not bypass these triggers — they are user triggers; ALTER TABLE ... DISABLE TRIGGER is required." PostgreSQL's default user triggers are exactly the class of triggers suppressed by `session_replication_role = replica`. In a break-glass document, this is dangerous because it creates a wider trigger-disabled window than the operator thinks they have.

**Fix:** Remove `SET LOCAL session_replication_role = 'replica'` from the runbook and explicitly warn not to use it for fiscal_events maintenance. Keep the narrow, named `ALTER TABLE fiscal_events DISABLE/ENABLE TRIGGER ...` sequence, with re-enable before commit.

## Implementer Deviation Assessment
The implementation is close to Task 8 on the mechanical points: the frozen-column comparisons use `IS DISTINCT FROM`; every frozen column name matches the Task 7 schema; the allowed column names exist; `BEFORE TRUNCATE` correctly uses `FOR EACH STATEMENT`; `down()` drops triggers before the function; and the pending→failed path rejects payload writes because payload can only be written on a transition to `parsed`. The major deviation is that the generic integrity resolver path is too broad for canonical_parse_failure and lets production create a verified, still-unparsed fiscal event, which contradicts the Task 24 resume contract.

## CI Gap Assessment
The SQLite skip is not acceptable as currently wired. The test file is PG-only by design, but the PG-backed merge gate in `.github/workflows/ci.yml` uses a hard-coded filter that excludes `FiscalEventsImmutabilityTest`; I found no available `feedback_audit_ci_gate_check.md` file or CI discovery rule that would pick up the migration name. This is a P1 until the PG gate explicitly runs the new Task 8 test or proves discovery coverage.
