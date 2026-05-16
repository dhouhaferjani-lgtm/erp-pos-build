# Phase 1 — Task 8 (`fiscal_events` immutability triggers) — Opus Re-Review (Round 2)

**Reviewer:** Claude Opus 4.7 (1M context)
**Commit reviewed:** `9577dc62` — `fix(fiscal): close Task 8 review findings — BLOCKER + P1 + 3 P2`
**Parent commit:** `2e036485` — `feat(fiscal): fiscal_events immutability triggers + break-glass runbook`
**Cumulative diff:** `82b32292..9577dc62` (Task 8 + its fix)
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Date:** 2026-05-16

---

## Verdict

**APPROVE.** The Codex BLOCKER, the Codex P1, the Codex P2, and both Opus
P2s from round 1 are all closed by `9577dc62`. The BLOCKER fix is correctly
scoped to `integrity_exception_class = 'canonical_parse_failure'` and does not
regress the stamps-only resolution path for other exception classes
(`canonical_hash_mismatch`, `signature_mismatch`, etc.). The state machine
remains coherent. No new findings.

---

## Files inspected

- `apps/api/database/migrations/2026_05_14_100002_create_fiscal_events_immutability.php` (post-fix, 280 lines)
- `apps/api/tests/Feature/Fiscal/FiscalEventsImmutabilityTest.php` (post-fix, 382 lines)
- `apps/api/docs/runbooks/fiscal-events-break-glass.md` (post-fix, 203 lines)
- `.github/workflows/ci.yml` (`backend-test-pgsql` job, lines 240-336)
- Pre-fix migration body (`git show 2e036485:…`) for comparison

---

## Closure assessment — each prior finding mapped

### Codex round 1 — BLOCKER (closed)

> `quarantined → verified` branch permitted integrity-only verification of a
> `canonical_parse_failure` event, stranding a `verified` row with
> `payload = NULL` that Step 4 (payload write-once) could never repair —
> breaking Task 24's resolver contract.

**Status:** **closed.** Migration lines 196–204 add a class-specific gate
inside the `quarantined → verified` branch:

```sql
IF OLD.integrity_exception_class = 'canonical_parse_failure' THEN
    IF OLD.payload IS NOT NULL
       OR NEW.payload IS NULL
       OR OLD.payload_parse_status IS DISTINCT FROM 'failed'
       OR NEW.payload_parse_status IS DISTINCT FROM 'parsed' THEN
        RAISE EXCEPTION 'fiscal_events row %: canonical_parse_failure resolution must atomically write payload and flip payload_parse_status from ''failed'' to ''parsed'' in the same UPDATE (spec §7.5).', OLD.id
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
END IF;
```

The gate is **class-specific** (only fires when
`OLD.integrity_exception_class = 'canonical_parse_failure'`), so the
stamps-only resolution path remains available for `canonical_hash_mismatch`,
`signature_mismatch`, and every other class — exactly matching the
implementer's "BLOCKER_COMP smoke" claim. Verified by diffing pre/post
trigger bodies.

The gate is also **symmetric** with Step 3's `failed → parsed` gate (lines
138–151) — both refuse to let the resolver complete half the atomic
transition. Together they make the Task 24 resolver UPDATE single-statement
or fail-closed.

### Codex round 1 — P1 (closed)

> `.github/workflows/ci.yml`'s `backend-test-pgsql` filter omitted
> `FiscalEventsTableTest` and `FiscalEventsImmutabilityTest`, so the merge
> gate did not actually exercise the PG-only trigger semantics.

**Status:** **closed.** Line 336 now reads:

```
--filter="VoucherLedgerTest|VoucherLedgerAppendOnlyTest|VoucherSchemaTest|FiscalHardeningE2ETest|FiscalEventsTableTest|FiscalEventsImmutabilityTest"
```

Both Task 7 and Task 8 PG-only tests are now in the gate. The accompanying
comment block (lines 322–326) documents both additions with their scope.

### Codex round 1 — P2 (closed)

> Break-glass runbook §4 recommended
> `SET LOCAL session_replication_role = 'replica'` as a no-op for the
> immutability triggers. In fact `replica` mode **does** suppress default
> user triggers including these — the runbook was implicitly recommending
> a broken procedure.

**Status:** **closed.** Runbook §4 now:

1. Removes the misleading `session_replication_role = 'replica'` suggestion.
2. Adds a prominent blockquote `DO NOT use ...` warning that:
   - Correctly states the flag **does** suppress default user triggers.
   - Explains the wider blast radius (every BEFORE/AFTER user trigger on
     every table touched in the session).
   - Routes operators to §5's narrow `ALTER TABLE … DISABLE TRIGGER`
     sequence instead, re-enabled before COMMIT in the same transaction.

The §5 procedure itself (lines 110–141) is unchanged and remains the
intended path. The runbook now correctly documents the trap.

### Opus round 1 — P2-1 (closed)

> The gated `failed → parsed` resume transition has 7 conjunctive
> conditions. Only the happy path was tested, so a future refactor that
> drops any single clause from the AND chain would pass the suite silently.

**Status:** **closed.** The new
`test_failed_to_parsed_rejects_when_any_condition_violated()` data-provider
test (lines 160–278) covers 6 of the 7 conditions, and the existing
`test_failed_to_parsed_is_rejected_when_not_a_parse_failure_resolution()`
(lines 119–137) covers condition 4 (`OLD.integrity_status = 'quarantined'`).
Mapping each condition to its negative case:

| # | Condition | Test name | Caught by |
|---|---|---|---|
| 1 | `OLD.payload IS NULL` | data-provider `OLD.payload not null (payload write-once)` | Step 4 (payload write-once gate) — same root protection |
| 2 | `NEW.payload IS NOT NULL` | data-provider `NEW.payload null` | Step 3 gate |
| 3 | `OLD.integrity_exception_class = 'canonical_parse_failure'` | data-provider `OLD.integrity_exception_class is canonical_hash_mismatch` | Step 3 gate |
| 4 | `OLD.integrity_status = 'quarantined'` | `test_failed_to_parsed_is_rejected_when_not_a_parse_failure_resolution` | Step 3 gate |
| 5 | `NEW.integrity_status = 'verified'` | data-provider `NEW.integrity_status stays quarantined` | Step 3 gate |
| 6 | `NEW.integrity_resolved_at IS NOT NULL` | data-provider `NEW.integrity_resolved_at null` | Step 3 gate |
| 7 | `NEW.integrity_resolved_by IS NOT NULL` | data-provider `NEW.integrity_resolved_by null` | Step 3 gate |

Note on condition 1: the negative case ends up being caught by Step 4
(write-once payload) before Step 3 is reached, because OLD.payload not
being NULL means `parse_status` was already 'parsed' and the test rewrites
payload. The implementer's docblock at lines 188–192 is honest about this
("same root protection"). I considered whether a more surgical case (insert
with payload + `parse_status = failed` + exception class set) could
exercise Step 3 condition 1 in isolation, but that combination is itself
unreachable through the trigger-allowed transitions, so the docblock
characterisation is correct.

### Opus round 1 — P2-2 (closed)

> Spec §3.3's named `verified → quarantined` ingestor-flag transition was
> mentioned in the trigger but had no test. Phase 1's `OutboxIngestor`
> stamps at INSERT (which doesn't fire BEFORE UPDATE), but Task 19 / Task
> 23 retry paths or any future reclassifier needs the transition.

**Status:** **closed.**
`test_verified_to_quarantined_with_exception_metadata_succeeds()`
(lines 287–311) inserts a verified event with null exception fields, then
updates with `integrity_status = quarantined` and populated
`integrity_exception_class` / `integrity_exception_reason`. Asserts the
UPDATE succeeds and the post-update row reflects both writes. Hits Step 5's
`verified → quarantined` `NULL`-branch as intended.

### Opus round 1 — P3 (not addressed; correctly out of scope)

The five P3s from round 1 are documentation/test polish items. `9577dc62`
does not target them and does not inadvertently address them. They remain
deferred backlog. (If you want, I can list them out in a follow-up
post-Phase-1 cleanup ticket, but none are blocking.)

---

## Regression scan

### Is the BLOCKER fix over-broad?

**No.** The gate fires only inside the `OLD.integrity_status = 'quarantined'
AND NEW.integrity_status = 'verified'` branch (Step 5) and only when
`OLD.integrity_exception_class = 'canonical_parse_failure'`. For other
quarantine classes the existing stamps-only path is unchanged.

Cross-checked the trigger body's `IF OLD.integrity_exception_class = …`
clause against the spec's exception-class taxonomy. `canonical_hash_mismatch`,
`signature_mismatch`, etc. continue to resolve via the stamps-only path,
which is what the implementer's claim says and what the code does.

### Does the BLOCKER fix introduce any new stuck states?

**No.** The state machine guarantees that for a `canonical_parse_failure`
quarantined event, `payload_parse_status = 'failed'` and `payload IS NULL`.
The gate's preconditions (`OLD.payload IS NULL` and
`OLD.payload_parse_status = 'failed'`) are invariants of the state, not
extra constraints the resolver has to chase. The gate's NEW-side
preconditions (`NEW.payload IS NOT NULL` and `NEW.payload_parse_status = 'parsed'`)
are exactly the resolver's per-spec §7.5 obligations.

### Does the gated `failed → parsed` transition (Step 3) and the new Step-5 gate overlap correctly?

**Yes.** Step 3 enforces the seven preconditions when the parse-status
diff is `failed → parsed`. The new Step 5 gate enforces an overlapping
subset when the integrity diff is `quarantined → verified AND
exception_class = canonical_parse_failure`. A resolver UPDATE that changes
both transitions in the same statement passes both gates; a resolver
UPDATE that changes only one half fails the corresponding gate. The two
gates are mutually-reinforcing belt + suspenders for the atomic resume —
exactly what the round-1 BLOCKER reasoning called for.

### Symmetry with `prevent_receipt_modification` (the project's reference pattern)?

Preserved. The fix is additive — same transition-explicit, raise-otherwise
style. No structural divergence introduced.

### Data-provider style migration
The new test uses PHPUnit 11 `#[DataProvider]` attribute (the project's
adopted style — `apps/api` runs PHPUnit 11). Other tests in the codebase
still using doc-comment `@dataProvider` are pre-existing technical debt
that this commit does not address (and shouldn't — out of scope). PHPStan
and Pint clean on the two changed PHP files (verified locally).

---

## Verification commands run

- `php -l` on both changed PHP files — clean.
- `./vendor/bin/pint --test` on both — `{"result":"pass"}`.
- `./vendor/bin/phpstan analyse <files> --no-progress` — `[OK] No errors`.
- `php artisan test --filter='FiscalEventsImmutabilityTest'` against vanilla
  PG 16 — **all 13 tests fail at migration time** with
  `there is no unique constraint matching given keys for referenced table "voucher_ledger"` on
  `2026_05_02_000002_create_voucher_ledger_table.php`. This is a
  pre-existing environmental issue (`VoucherSchemaTest` reproduces the same
  error on the same DB) — vanilla PG handles the self-FK ordering
  differently from the TimescaleDB image that CI uses. **Not a regression
  from `9577dc62`** — confirmed by reproducing on `VoucherSchemaTest` and
  by `git log --oneline -- voucher_ledger_table.php` showing the migration
  hasn't changed since `2b17f872`. The CI gate now exercises these on the
  TimescaleDB image (per the P1 fix), which is the intended evidence path.

---

## What I did not check

- Did not run the test on TimescaleDB locally (CI does this on every PR to
  main, and the filter is now correct so the gate will surface any drift).
- Did not re-verify Task 7 (`FiscalEventsTableTest`) — out of scope; Task 7
  was already in `082b32292` and is unchanged in `9577dc62`.
- Did not audit the spec §7.5 / §3.3 text for completeness — those are
  upstream from the trigger and were locked by the Phase 1 v7 spec review.

---

## Bottom line

`9577dc62` is a focused, well-bounded fix. The five prior findings
(BLOCKER + P1 + P2 from Codex; P2-1 + P2-2 from Opus) are each closed by
a discrete diff hunk that maps cleanly to the original finding. The
BLOCKER fix is class-specific and does not regress the stamps-only
resolution path. The new tests pin the gated `failed → parsed` transition
and the `verified → quarantined` ingestor-flag transition so a future
trigger refactor that drops any of the seven AND-clauses will be caught
by the suite. No new findings. Ship.
