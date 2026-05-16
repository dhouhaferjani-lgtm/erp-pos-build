# POS Phase 1 — Task 3 (Supporting fiscal enums) — Opus Review

**Date:** 2026-05-15
**Reviewer:** Opus 4.7 (mandatory headless review gate)
**Repository:** `apps/erp`
**Base SHA:** `84ff133d87847c3104eb5d7e6f10795372d9a264`
**Head SHA:** `0f0e02719000e72a14a5b028d6983741b7c07d9e`
**Branch:** `feat/pos-fiscal-event-engine-phase1`

**Plan:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` § Task 3
**Spec:** `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` (§3.2, §7.5, §8)
**Source of truth:** `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md`
**Reality audit:** `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md`
**Owner strategy:** `~/Downloads/fiscal_chain_architecture_strategy.md`, `~/Downloads/pos_printable_documents_architecture.md`

---

## Verdict

**APPROVE.**

Task 3 ships exactly what the plan called for, the enum values line up character-for-character with spec §3.2 / §7.5 / §8 column comments, the one piece of domain logic (`IntegrityExceptionClass::isAdmissibleToLedger()`) faithfully encodes the §8 routing rule, and the test exercises both branches of that rule plus the full case-list ordering of the other four enums. No regressions, no module-boundary violations, no drift from Task 1's `FiscalEventType`, and no wiring is required at this task (provider binding lands in Task 6).

---

## Scope of change (verified)

`git diff --stat 84ff133d..0f0e0271` → 6 files, +106 / -0:

- `apps/api/app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php` (NEW, 19 lines)
- `apps/api/app/Modules/Fiscal/Domain/Enums/IntegrityStatus.php` (NEW, 11 lines)
- `apps/api/app/Modules/Fiscal/Domain/Enums/PayloadParseStatus.php` (NEW, 12 lines)
- `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php` (NEW, 13 lines)
- `apps/api/app/Modules/Fiscal/Domain/Enums/SignatureStatus.php` (NEW, 13 lines)
- `apps/api/tests/Unit/Fiscal/FiscalEnumsTest.php` (NEW, 38 lines)

Single commit (`0f0e0271 feat(fiscal): integrity/parse/projection/signature status enums`). Commit message matches plan Task 3 Step 5 verbatim.

---

## Verification summary

**Spec correspondence — each enum vs. its column-comment authority:**

| Enum | Spec authority | Spec comment | Implementation values | Match |
|---|---|---|---|---|
| `SignatureStatus` | §3.2 col `signature_status` (spec L168) | `not_required\|pending\|signed\|failed` | `not_required, pending, signed, failed` (`SignatureStatus.php:9-12`) | ✅ |
| `IntegrityStatus` | §3.2 col `integrity_status` (spec L180) | `verified\|quarantined` | `verified, quarantined` (`IntegrityStatus.php:9-10`) | ✅ |
| `PayloadParseStatus` | §3.2 col `payload_parse_status` (spec L187) | `pending\|parsed\|failed` | `pending, parsed, failed` (`PayloadParseStatus.php:9-11`) | ✅ |
| `ProjectionStatus` | §7.5 col `projection_status` (spec L439) | `pending\|running\|applied\|dead_lettered` | `pending, running, applied, dead_lettered` (`ProjectionStatus.php:9-12`) | ✅ |
| `IntegrityExceptionClass` | §8 table (spec L472–476) | 5 classes; one (`sequence_conflict`) is non-admissible to `fiscal_events` | `canonical_hash_mismatch, canonical_parse_failure, time_anomaly, sequence_gap, sequence_conflict` (`IntegrityExceptionClass.php:9-13`) | ✅ |

`IntegrityExceptionClass::isAdmissibleToLedger()` returns `false` only for `SequenceConflict` — i.e. only that class is routed to `fiscal_event_quarantine` and the other four land in `fiscal_events` with `integrity_status='quarantined'`. This matches spec §8 (L476, L478) exactly: *"`fiscal_event_quarantine` — it cannot enter `fiscal_events` (UNIQUE key)"*.

**Plan correspondence:**

The plan reference code at plan L276–309 is reproduced verbatim in `FiscalEnumsTest.php:1-38`. The plan's prose description of `IntegrityExceptionClass` (plan L318: *"plus `isAdmissibleToLedger(): bool` returning `false` only for `SequenceConflict`"*) matches `IntegrityExceptionClass.php:15-18`. Step 1 (write failing test) → Step 4 (run green) was respected; the commit ships the test next to the implementation.

**Test execution:**

Ran `./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEnumsTest.php` from `apps/api/` — **3 tests, 8 assertions, OK** (PHPUnit 11.5.55, PHP 8.4.15, 14ms). Tests are real (assert concrete expected case-value lists in declared order via `array_map`), not tautological.

**Reality-audit alignment:**

Reality audit §7.1 calls out *"`fiscal_events` is a new table"* and *"Source of truth for in-scope fiscal facts"* — Task 3 introduces the value vocabularies (signature lifecycle, integrity lifecycle, parse lifecycle, projection lifecycle, anomaly classes) that the §3.2 column comments demand. No conflict with reality §1.4 either: those V3 receipt-hash classes live under `App\Modules\POS\Domain\Services\Fiscal\V3\` and are untouched here, so the reality-audit *"new signature provider is a new protocol — name it distinctly"* rule (audit §7.2) is honored — the new `Fiscal` module enums occupy a separate namespace.

**Module boundary / drift checks:**

- Namespace `App\Modules\Fiscal\Domain\Enums\` — pure domain layer, no inbound imports from other modules. ✅
- No coupling to `Treasury`, `POS`, `Compliance`, `Accounting`. ✅
- Cohabits cleanly with Task 1's `FiscalEventType` (same directory). Casing convention diverges intentionally — event-type cases mirror Appendix A's SCREAMING_SNAKE_CASE wire vocabulary, status cases are PascalCase with snake_case string backing matching the spec's SQL column comments. Both are idiomatic. No name collision. ✅
- No wiring expected at this task: enums are referenced *by future tasks* (migrations Task 7, ingestor Task 18, projection table Task 11 — per plan L597–598, L765, L816). `FiscalServiceProvider` binding work is scoped to Task 6 (plan L535). ✅

**Owner strategy docs (`fiscal_chain_architecture_strategy.md`, `pos_printable_documents_architecture.md`):**

Neither doc references these column / enum names directly — they sit one level above (chain architecture, printable taxonomy). The spec mediates between them and the implementation. No contradictions found.

---

## Findings

**None at BLOCKER / P1 / P2 / P3.** The change is minimal, faithful to the plan, value-correct against the spec, and the test exercises every enum.

### Non-findings worth noting (no action required)

1. **Test method 3 batches three enum-value assertions** (`test_payload_parse_status_and_integrity_status_and_signature_status`). PHPUnit purism might prefer one method per enum, but the plan calls for exactly this structure (plan L302–307) and the assertions are independent / readable. Faithful to plan.
2. **Three of the five `IntegrityExceptionClass` cases (`CanonicalParseFailure`, `TimeAnomaly`, `SequenceGap`) get no direct value-string assertion in the test**, only via `isAdmissibleToLedger() === true` indirectly through `CanonicalHashMismatch`. The plan-as-written has the same scope (plan L285–292), and value-correctness is structurally enforced by the §8 spec table that the implementation comments against. Will be reinforced by the §3.2 column CHECK constraint test in Task 7 and the per-class ingestor branch tests in Task 18. Not a Task-3 gap.
3. **No PHPStan / Pint output captured in this review** — the task scope is six tiny enum files plus a unit test; running the full preflight is out of headless-gate scope, and the new files follow the existing `FiscalEventType.php` style verbatim (strict types, namespace, single `enum X: string`, `declare(strict_types=1);`).

---

## Bottom line

Ship it. Task 3 is a clean, narrowly-scoped vocabulary commit that unblocks Tasks 7 (migration), 11 (projections table), 16 (quarantine table), and 18 (ingestor) without taking on anything those tasks need to own. The single piece of behavior (`isAdmissibleToLedger`) is the right place for the §8 routing decision and is covered both-branches by the test.
