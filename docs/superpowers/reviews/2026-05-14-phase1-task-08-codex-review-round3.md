APPROVE — The round-2 two-step reclassification bypass is closed by the new Step 1b write-once guard, and the new tests exercise direct DB updates rather than PHP-layer validation. Task 8 is done.

**Scope Checked**
- Round-2 bypass source: `docs/superpowers/reviews/2026-05-14-phase1-task-08-codex-review-round2.md:39-41` describes the blocker: change `integrity_exception_class` first, then verify with stamps only.
- Changed files in `73066080` over `9577dc62`: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php` and `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php`.

**Step 1 — Bypass Closure**
- The new guard runs inside the `TG_OP = 'UPDATE'` branch before payload-parse, payload, and integrity-status branches: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:68`, `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:128-136`, before Step 5 starts at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:200`.
- Exact SQL that closes Step 1 of the attack at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:128-136`:

```sql
IF OLD.integrity_exception_class IS DISTINCT FROM NEW.integrity_exception_class THEN
    IF OLD.integrity_exception_class IS NOT NULL THEN
        RAISE EXCEPTION 'fiscal_events row %: integrity_exception_class is write-once; once set on a quarantined row it cannot be changed (would bypass class-specific resolution guards — spec §3.3, §7.5).', OLD.id
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    -- OLD IS NULL, NEW IS NOT NULL: this is the verified->quarantined
    -- flag path. That transition is gated separately in Step 5; the
    -- class set itself is permitted here.
END IF;
```

- Therefore the exact round-2 Step 1 attack, updating `integrity_exception_class` from `canonical_parse_failure` to `canonical_hash_mismatch` on a quarantined/failed/null-payload row, raises at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:128-131` before the integrity-status branch at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:205-235` can be relevant.
- The later Step 2 attack, quarantined -> verified with stamps only, remains blocked for unchanged `canonical_parse_failure` rows because Step 5 checks `OLD.integrity_exception_class = 'canonical_parse_failure'` and raises unless payload is written and parse status flips failed -> parsed in the same update: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:222-229`.

**Step 2 — Guard Scope**
- INSERT can set `integrity_exception_class` freely because the migration creates only `BEFORE UPDATE`, `BEFORE DELETE`, and `BEFORE TRUNCATE` triggers, not a `BEFORE INSERT` trigger: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:256-270`.
- First-time set succeeds because Step 1b only raises when `OLD.integrity_exception_class IS NOT NULL`; when `OLD` is null and `NEW` is non-null, execution falls through: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:128-136`. The test covers this via a direct update from verified/null to quarantined/`canonical_hash_mismatch`: `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:313-334`.
- Same-class no-change succeeds because Step 1b is gated by `OLD.integrity_exception_class IS DISTINCT FROM NEW.integrity_exception_class`: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:128`.
- Unsetting is blocked because non-null `OLD.integrity_exception_class` with distinct `NEW` raises, including non-null -> null: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:128-131`; covered by `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:357-374`.
- Changing to a different non-null value is blocked by the same raise path at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:128-131`; covered by the bypass-shaped test at `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:337-354`.

**Step 3 — Other Allowed Columns**
- `integrity_exception_reason`, `integrity_resolved_at`, and `integrity_resolved_by` are still intentionally in the allowed mutable set in the whitelist error text at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:108`.
- I do not see a similar bypass through those columns: none can change `OLD.integrity_exception_class`, and the canonical-parse-failure verification guard still requires `NEW.payload IS NOT NULL` and `NEW.payload_parse_status = 'parsed'` at `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:222-229`.
- Pre-setting `integrity_resolved_at` or `integrity_resolved_by` cannot reproduce the round-2 null-payload verification bypass for `canonical_parse_failure`, because the same Step 5 branch still raises on `NEW.payload IS NULL` or `NEW.payload_parse_status IS DISTINCT FROM 'parsed'`: `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php:222-229`.

**Step 4 — PHPUnit Coverage Soundness**
- The three new tests added in `73066080` are `test_integrity_exception_class_can_be_set_on_verified_to_quarantined`, `test_integrity_exception_class_cannot_change_to_different_value`, and `test_integrity_exception_class_cannot_be_unset`: `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:313-374`.
- They are not tautological because they insert rows through `DB::table('fiscal_events')->insert($row)` and mutate them through direct `DB::table('fiscal_events')->where('id', $e)->update(...)` calls, which exercise PostgreSQL triggers rather than model validation: `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:325-329`, `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:351-354`, `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:370-373`, `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:467`.
- The P3 dataset now covers the partial resolver attack tuple: payload written, `payload_parse_status` omitted so it stays `failed`, and `integrity_status` flipped to `verified` with resolution stamps: `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:279-298`.
- Runtime note: `php artisan test --filter=FiscalEventsImmutabilityTest` exited 0 locally, but the current local connection is not PostgreSQL, so all 17 cases were skipped by `skipUnlessPostgres()` at `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php:472-476`.
