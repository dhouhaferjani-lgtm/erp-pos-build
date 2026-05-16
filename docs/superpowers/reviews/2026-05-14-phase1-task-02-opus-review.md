# POS Phase 1 — Task 2 (FiscalEventType enum) — Opus headless final review

**Plan:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (Task 2)
**Spec:** `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` (Appendix A; §5.0, §11, §14.2, §14.3)
**Research grounding:** `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md` (Appendix A — canonical taxonomy), `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md`, `~/Downloads/fiscal_chain_architecture_strategy.md`, `~/Downloads/pos_printable_documents_architecture.md`
**Base SHA:** `fc69ee27fe26d7c1e41631b13192ccc2cea2a9ed`
**Head SHA:** `84ff133d87847c3104eb5d7e6f10795372d9a264`
**Commit:** `84ff133d feat(fiscal): FiscalEventType enum with Appendix A reserved values`
**Diff surface:** 2 files, +101 / −0 (purely additive; no existing file touched)
- `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php` (new, 55 LoC)
- `apps/api/tests/Unit/Fiscal/FiscalEventTypeTest.php` (new, 46 LoC)

---

## Verdict: **APPROVE**

Both prior-review minor findings are resolved (P2 test gap closed; P3 framework helper removed). The 28 Appendix A values match spec v7 exactly in spec order; the four `isImplementedInPhase1()` cases match the §5.0/§11 implemented set; the Domain enum is now framework-free; the migration-contract helper `checkConstraintList()` is properly exercised. No regression surface (additive-only diff), no module-boundary violation, no drift from Task 1's `FiscalServiceProvider` / `PreflightFiscalGateCommand`, no provider wiring owed by this task.

---

## Findings

### BLOCKER
None.

### P1
None.

### P2
None.

### P3
None blocking. One tiny optional polish noted in the cross-reference section below (the implemented-set "exclusion" test only spot-checks 4 of 24 reserved types); not load-bearing because Task 6's `FiscalEventPayloadRegistry` and Task 8's ingestion path will detect any accidental Phase-1 flip downstream.

---

## Verification of prior-review fixes

| Prior finding | Fix verified |
| --- | --- |
| **P2** — `checkConstraintList()` defined but never invoked by any assertion (prior review §P2) | **RESOLVED.** `FiscalEventTypeTest.php:33-45` adds `test_check_constraint_list_quotes_every_case_and_uses_comma_space_separator`, which (a) calls `FiscalEventType::checkConstraintList()`, (b) asserts every case appears single-quoted, (c) verifies 28 quoted pairs and 27 comma-space separators (no trailing separator, no missing case), (d) anchors the string with `assertStringStartsWith("'SALE_RECEIPT'")` and `assertStringEndsWith("'REPRINT_COPY'")`. This is the exact assertion the prior review prescribed; it closes the Task-7 migration-contract risk (a silent regression in the formatter would now fail this test before Task 7's `CHECK (event_type IN (...))` migration could run). |
| **P3** — Laravel `collect()` helper used inside the Domain enum (prior review §P3 — framework seam leak under the hexagonal-architecture rule from `apps/erp/CLAUDE.md`) | **RESOLVED.** `FiscalEventType.php:48-54` now uses pure-PHP `implode(', ', array_map(static fn (self $case): string => "'".$case->value."'", self::cases()))`. Identical output, zero framework dependency. Domain layer is clean. |

Both fixes match the prior review's prescribed code verbatim.

---

## Verification summary

| Check | Result |
| --- | --- |
| All 28 Appendix A values present, spelling identical to spec v7 §Appendix A line 786-790 | **PASS** — cross-checked spec lines 788 (4 implemented) + 790 (24 reserved) against `FiscalEventType.php:9-36`; perfect match. |
| Cases declared in the spec's order (implemented first, then reserved) | **PASS** — `:9-12` are the 4 implemented; `:13-36` are the 24 reserved in spec order. |
| Implemented set = `{SALE_RECEIPT, CHAIN_BREAK_DETECTED, CHAIN_RESTART, TERMINAL_REGISTRY_SNAPSHOT}` (spec §11, §5.0, Appendix A line 788) | **PASS** — `isImplementedInPhase1()` at `:38-46` returns true only for those four. |
| Backed-string enum with `value === case name` (DB CHECK + JSON envelope alignment) | **PASS** — every `case X = 'X'`. |
| `checkConstraintList()` produces the exact SQL fragment Task 7's migration consumes | **PASS** — manually evaluated: `'SALE_RECEIPT', 'CHAIN_BREAK_DETECTED', ..., 'REPRINT_COPY'` (28 quoted values, 27 `', '` separators, no trailing comma). Also test-guarded now (see prior-fix table). |
| File paths and namespace match plan §"Task 2" lines 174-176, 184 | **PASS** — `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php` + `apps/api/tests/Unit/Fiscal/FiscalEventTypeTest.php`; namespace `App\Modules\Fiscal\Domain\Enums`. |
| Test extends `PHPUnit\Framework\TestCase` (pure unit; no Laravel boot, no DB) | **PASS** — `FiscalEventTypeTest.php:8,10`. |
| Tests are real, not tautological | **PASS** — `isImplementedInPhase1` tests exercise actual behavior on real cases; `checkConstraintList` test parses the produced string and verifies shape against the case set (would catch a missing quote, wrong separator, dropped case, or trailing comma). |
| No existing files modified (regression scope) | **PASS** — `git diff fc69ee27..84ff133d --name-only` returns only the two new files. |
| Module boundary respected (Fiscal Domain has no inbound dependencies on other modules; no Laravel imports) | **PASS** — only imports `PHPUnit\Framework\TestCase` (test) and the enum (self). No `Illuminate\*`, no `collect()`, no `app()`. Complies with root `CLAUDE.md` rule 13 (constructor injection / no `app()` helper) and `apps/erp/CLAUDE.md` rule 6 (module boundaries). |
| Type/name drift from Task 1 artefacts (`FiscalServiceProvider`, `PreflightFiscalGateCommand`) | **N/A** — this task references neither; no drift surface. |
| Provider wiring owed by this task | **None** — plan §Conventions enumerates wiring per task: Task 2 has no wiring (the enum is a value type, registered nowhere). Task 6 binds `FiscalIntegrityProvider`; Task 18 registers `FiscalEventProjectionRegistry`; Task 20 loads routes; etc. Nothing is owed here. |
| Forward-compat with Task 7 CHECK migration (`CHECK (event_type IN (...))`) | **PASS** — `checkConstraintList()` is the source of truth; Task 7's reviewer must confirm the migration calls it rather than duplicating the list. The format guard test will catch any accidental contract drift on the enum side. |
| Forward-compat with Task 6 `FiscalEventPayloadRegistry` + Task 8 `append()` throwing `FiscalEventTypeNotImplemented` | **PASS** — those tasks read `isImplementedInPhase1()` / the case set; both are stable and exactly what the spec contracts (§Appendix A, §11). |
| Spec-research alignment (SoT v3 Appendix A vs. spec v7 Appendix A) | **PASS** — spec v7 is a strict superset of SoT v3 §295 (adds `ACCOUNT_PAYMENT_RECONCILED` + `IDENTITY_ALIAS_RECONCILED`, which §13.2 and §13.4 of v7 introduce as reserved types for the Phase 2+ customer-accounts flows). The enum mirrors spec v7, which is the binding contract. |
| Pint/PHPStan/CS posture (visual inspection — file is conventional, strict-typed, no unused imports, no `mixed`) | **PASS** — `declare(strict_types=1)`, single-class file, return-typed methods. |
| TDD red→green discipline | **N/A here** — same observation as prior review: a single commit ships both files, so the red step isn't visible in git history, but the test class would have aborted with class-not-found before the enum existed. Discipline at-worst undocumented, not violated. Not a finding. |

---

## Cross-reference notes (not findings)

- **Spec ↔ enum cross-reference (28 values, verified one-to-one).** Spec v7 Appendix A line 788 (implemented, 4): `SALE_RECEIPT, CHAIN_BREAK_DETECTED, CHAIN_RESTART, TERMINAL_REGISTRY_SNAPSHOT` → enum `:9-12`. Spec v7 Appendix A line 790 (reserved, 24): `COMPANY_DAY_CLOSURE_MANIFEST, ACCOUNT_PAYMENT, ACCOUNT_CHARGE, ACCOUNT_REFUND, ACCOUNT_PAYMENT_RECONCILED, ACCOUNT_CREDIT_ISSUE, ACCOUNT_CREDIT_USAGE, DEPOSIT_RECEIPT, IDENTITY_ALIAS_RECONCILED, SALE_VOID, SALE_CORRECTION, REFUND_RECEIPT, PARTIAL_REFUND, RETURN_WITHOUT_RECEIPT, OPENING_FLOAT, CASH_IN, CASH_OUT, SAFE_DROP, CASH_CORRECTION, SESSION_OPEN, SESSION_CLOSE, X_REPORT, Z_REPORT, REPRINT_COPY` → enum `:13-36`. Order matches; spelling matches; no duplicates; no missing.
- **§14.2 / §14.3 carve-out alignment.** `SALE_VOID`, `SALE_CORRECTION`, `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT` are present and reserved — they underpin the "void/processReturn knowingly retained server-side for Phase 1" carve-out and the §14.3 two-chokepoint rule's exclusion of non-new-sale mutations. Their reservation here is load-bearing for the v5/v6 P2-1 / Codex BLOCKER resolutions and matches §14.2 line 649 verbatim.
- **No consumers yet.** `grep -rn FiscalEventType apps/api` returns only the two new files (verified). All downstream consumers (`FiscalEvent` model cast in Task 7, `FiscalEventPayloadRegistry` in Task 6, `OutboxIngestor` in Task 20, `PosCoreReceiptProjection` in Task 21, the projection registry in Task 18) will reference this enum once they land; nothing else can drift today.
- **Optional micro-polish (not a finding).** The `test_reserved_types_are_not_implemented` case (`:20-26`) spot-checks 4 of the 24 reserved types; a future flip of, say, `SESSION_OPEN` into `isImplementedInPhase1()`'s array would not fail this test. A defensive iteration —
  ```php
  foreach (FiscalEventType::cases() as $case) {
      $expected = in_array($case, [
          FiscalEventType::SALE_RECEIPT,
          FiscalEventType::CHAIN_BREAK_DETECTED,
          FiscalEventType::CHAIN_RESTART,
          FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT,
      ], true);
      $this->assertSame($expected, $case->isImplementedInPhase1(), $case->value);
  }
  ```
  — would close the residual gap. Not load-bearing because Task 6 (`FiscalEventPayloadRegistry` — `append()` must resolve a payload DTO for every implemented type) and Task 8 (the `FiscalEventTypeNotImplemented` throw path) would catch the regression downstream. Mentioning for the next-iteration backlog only; **not a merge blocker**.
- **Vestigial test name (not a finding).** `test_check_constraint_list_matches_appendix_a` (`:28-31`) now only asserts the case count (`assertCount(28, ...)`) — the actual constraint-list assertions live in the new `test_check_constraint_list_quotes_...` test. The prior review explicitly approved keeping the older test as a case-count guard or folding it; either is fine. Rename to `test_appendix_a_has_28_cases` would be clearer but is not worth a churn commit.

---

## Recommendation

Merge as-is. The implementation is correct, the spec contract is locked in, the prior-review findings are cleanly addressed, and the optional polish (full implemented-set sweep) can ride in a later cleanup commit if at all.
