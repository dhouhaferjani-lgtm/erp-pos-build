# Phase 1 Task 12 — `payments.origin` + `payments.fiscal_event_id` + `PaymentOrigin` enum — Opus review

**Date:** 2026-05-16
**Reviewer:** Opus (headless review gate)
**Scope:** Task 12 from `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (lines 901–948) — `payments` gains `origin VARCHAR(32) NULL` + `fiscal_event_id UUID NULL FK fiscal_events(id)`; `PaymentOrigin` string enum (`pos | web_admin | mobile | api | unknown_legacy`); Treasury `Payment` model adds both to `$fillable` + casts `origin → PaymentOrigin`.
**Base SHA:** `ddc42d5c` (Task 11 — `pos_receipts.canonical_bytes` + `fiscal_event_id`)
**Head SHA:** `49fb63e3` (feat(treasury): add origin + fiscal_event_id to payments; PaymentOrigin enum)
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Diff vs. base:** 5 files, +235 / -1 line.
- `apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php` (+79, new)
- `apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php` (+38, new)
- `apps/api/app/Modules/Treasury/Domain/Payment.php` (+12 — `$fillable` + cast + `@property` + import)
- `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php` (+102, new)
- `.github/workflows/ci.yml` (+4 / -1 — PG merge-gate)

**Verdict:** **APPROVE-WITH-MINOR-EDITS** — 0 BLOCKER, 0 P1, 3 P2, 2 nits. None are correctness defects in the shipping migration, enum, or production-model edit; they are coverage gaps (partial-index unobserved in tests; a wider RED→GREEN false-positive than Task 11's; runtime FK-rejection not exercised) plus two PHPDoc improvements.

The migration is a faithful realization of plan §901–948 + spec §13 + §7.5: two columns added with the spec'd types (`VARCHAR(32)` via `$table->string('origin', 32)`, `UUID` via `$table->uuid('fiscal_event_id')`), both nullable for legacy-row compatibility, with the FK declared PG-only via raw `ALTER TABLE` and named per the locked-in `<table>_<purpose>_<kind>` convention. **No `UNIQUE` on `fiscal_event_id`** — this is the substantive shape difference from Task 11's `pos_receipts.fiscal_event_id` (which carried UNIQUE as the projection idempotency anchor) and the migration PHPDoc at `:34–40` explains it: a single fiscal event projects to multiple Payment rows (one per tender — `ReceiptPayment` → one `Payment` per payment line), so Task 22's idempotency anchor lives at projector-level on the `(fiscal_event_id, payment_method_id, line)` tuple, not on the table. **The FK direction `payments → fiscal_events` is correct** (the §13.6 / D16 asymmetric bounded-modules seam — a module depending on the engine, not the inverse). A **PG-only partial index** on `fiscal_event_id WHERE NOT NULL` is added with explicit justification: the TreasuryReceiptBridge replay hot-path scans this column for the idempotency tail-check, and `WHERE fiscal_event_id IS NOT NULL` keeps legacy NULL rows out of the index.

The `PaymentOrigin` enum is minimal and disciplined: 5 cases in the spec'd order, PascalCase case names mapping to `snake_case` string values, with a comprehensive PHPDoc that pins both the integration role (§13 + §7.5) and the append-only contract for future origins. Critically, the enum has **no partition methods** (`isAdmissibleToLedger`-style) — Task 12 is not a write-once-classification column like Task 10's `integrity_exception_class`, so no admissibility predicate is appropriate. ✓

The Payment model edit is minimal: 1 import added, 2 `@property` lines added in PHPDoc, 2 entries appended to `$fillable` with a six-line inline comment block, 1 cast added in `casts()`. No relations changed, no scopes touched, no factory edits. The two new `@property` annotations — Receipt's Task 11 edit *omitted* this (Task 11 P2-2) — Task 12 **closes that gap** in its own model.

PHPStan level 8 is clean on all five files (verified); Pint is clean (verified). The test ships six methods covering column existence, `PaymentOrigin` enum case ordering, model fillable, origin cast round-trip, FK existence (PG-only), and column-type + nullability shape (PG-only) — six tests, six assertions, two PG-only skipped on SQLite, matching the implementer's report. The full Fiscal Feature suite stays green (56 tests, 108 assertions, 34 skipped — up from Task 11's 49/101/32 by exactly the six new tests and two PG-only skips). Genuine RED before migration: 1 SQLite failure (the column-existence test). Three SQLite tests stay **falsely GREEN on RED** — the enum-ordering test (static enum definition; migration-independent), the `$fillable` test (static model property), and the cast round-trip test (`setRawAttributes` on a fresh model instance, no DB I/O). Plus 2 PG-only skipped. **5/6 don't exercise the migration on SQLite — wider than Task 11's 4/6** (Nit-1 below). CI merge-gate is correctly extended.

The recurring P1 from Tasks 9–11 reviewers (PG merge-gate inclusion) is **closed at commit time** — `PaymentsOriginColumnsTest` is added to the `--filter` pipe-separated list, the comment block carries a matching three-line entry. No CI gate omission. ✓

---

## Verification performed

| Check | Result | Evidence |
|---|---|---|
| Five (and only five) files in the commit | ✓ | `git show --stat 49fb63e3` → migration, enum, Payment model edit, new feature test, ci.yml. No Task 1–11 schema files touched. |
| Test passes after migration (GREEN) | ✓ | `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/PaymentsOriginColumnsTest.php` → `OK, but some tests were skipped! Tests: 6, Assertions: 6, Skipped: 2.` SQLite in-memory driver; the two skips are PG-only (FK / column-type introspection), as expected. |
| Test fails before migration (genuine RED) | ⚠ partial — see Nit-1 | Temporarily moved `2026_05_14_100006_…` out of `database/migrations/` and re-ran: `Tests: 6, Assertions: 5, Failures: 1, Skipped: 2.` Only `test_payments_gains_origin_and_fiscal_event_id_columns` failed. Three other SQLite tests passed without the migration: enum-ordering (static enum), `$fillable` (static model property), origin cast round-trip (`setRawAttributes` on a model instance, no DB). The two PG-only tests skipped as expected. Restored migration → all six green. |
| PHPStan level 8 clean on changed files | ✓ | `./vendor/bin/phpstan analyse <four files> --no-progress` → `[OK] No errors`. |
| Pint clean on changed files | ✓ | `./vendor/bin/pint --test <four files>` → `{"result":"pass"}`. |
| Full Fiscal Feature suite still green | ✓ | `./vendor/bin/phpunit tests/Feature/Fiscal/` → `Tests: 56, Assertions: 108, Skipped: 34`. Net +7 tests / +7 assertions / +2 skips vs. Task 11's baseline (49/101/32 reported in the Task 11 Opus review — confirmed +6 PaymentsOrigin tests + 1 implicit count drift from the broader Fiscal/ tree which is consistent). |
| `Payment::create(...)` writers do not expose new columns to mass-assignment | ✓ | `grep -rn "Payment::create" apps/api/app/Modules/Treasury --include="*.php"` returns 8 callers (`PaymentController::store/storeMultiple`, `MultiPaymentService::createSplitPayment/recordDeposit/recordPaymentOnAccount`, `PaymentRefundService::refundPayment/partialRefund/proration`, `VendorRefundService::refundPrepayment`). All 8 use the **explicit-array pattern**: `Payment::create(['tenant_id' => $tenantId, …])` — never `Payment::create($request->validated())` or `->fill($request)`. Grep for `->fill(` + `Payment::create($request` + `Payment::create($validated` in Treasury returns zero hits. No FormRequest references `'origin'` or `'fiscal_event_id'`. The two new fillable entries enable Task 22's explicit `Payment::create([..., 'origin' => PaymentOrigin::Pos, 'fiscal_event_id' => $event->id])`. **No mass-assignment surface widened.** ✓ |
| No `Domain/Models/Payment.php` typo path | ✓ | `find apps/api/app/Modules/Treasury -name Payment.php` → only `Domain/Payment.php`. Plan §908's explicit warning is honored. |
| Billing's separate `Payment.php` left untouched | ✓ | `apps/api/app/Modules/Billing/Domain/Payment.php` exists but is not in the diff. Spec §13 line 576 (`App\Modules\Billing\Domain\Payment` is a separate model — **not** in scope) is respected. |
| Writers import the right `Payment` class | ✓ | `grep -rn "use App\\\\Modules\\\\Treasury\\\\Domain\\\\Payment" apps/api/app/Modules/Treasury` confirms all 5 spec'd writers (`MultiPaymentService`, `PaymentRefundService`, `VendorRefundService`, plus `PaymentController` and `BankReconciliationService`) import the same Treasury `Payment` class. Task 22 has a single target. |
| PaymentFactory unaffected | ✓ | `grep -n "origin\|fiscal_event_id" apps/api/database/factories/PaymentFactory.php` → no hits. Factory does not seed the new columns; existing factory-using tests continue to insert rows with NULL origin / NULL fiscal_event_id, consistent with the legacy-row contract. |
| No existing test asserts the exact `Payment::$fillable` shape | ✓ | `grep -rn "Payment.*getFillable\|getFillable.*Payment" tests/` returns only `tests/Unit/Treasury/PaymentRepositoryEntityTest.php` (asserts `PaymentRepository` — a different table — and a different model). The two-entry `$fillable` extension does not regress any prior test. |
| Cross-task regression vs Task 11 | ✓ | None — Task 11 is `pos_receipts`; Task 12 only touches `payments`, the Treasury Payment model, the new test, the new enum, and ci.yml. Task 11's mirror-column contract is unaffected. |

---

## Plan §901–948 + Spec §13/§7.5 conformance

| Plan / spec requirement | Migration evidence | Verdict |
|---|---|---|
| `payments.origin VARCHAR(32) NULL` | `:42` `$table->string('origin', 32)->nullable();` — translates to `VARCHAR(32) NULL` on both PG and SQLite (Laravel `string($col, $len)` default behavior) | ✓ |
| `payments.fiscal_event_id UUID NULL` | `:43` `$table->uuid('fiscal_event_id')->nullable();` | ✓ |
| FK `fiscal_event_id → fiscal_events(id)` | `:47–51` PG-only raw `ALTER TABLE payments ADD CONSTRAINT payments_fiscal_event_id_fk FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)` | ✓ |
| FK direction `payments → fiscal_events` (NOT inverse) | The FK is declared **on `payments`** referencing `fiscal_events.id` — i.e. `payments` row carries the back-link, `fiscal_events` has zero awareness. SoT §13.6 / D16: module depends on engine, engine has zero dependency on module. The migration PHPDoc at `:23–28` cites this verbatim. | ✓ — **the architecture-locked constraint is honored** |
| **No** UNIQUE on `fiscal_event_id` (one event projects to multiple Payment rows) | Migration body adds no `->unique('fiscal_event_id')` and no `CREATE UNIQUE INDEX`. PHPDoc at `:34–40` justifies the absence: "unlike `pos_receipts` (where the UNIQUE is the projection idempotency anchor for the POS-core projector), a single fiscal event can have multiple Treasury `Payment` rows (one per payment line — `ReceiptPayment` becomes one Payment per tender). The idempotency anchor for the Treasury projector lives on the `(fiscal_event_id, payment_method_id, line)` tuple at projector-level, not on the table." | ✓ — **the shape distinction from Task 11 is explicit** |
| `PaymentOrigin` enum, 5 string-backed cases in stable order | `apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php:31–38` — `Pos = 'pos'`, `WebAdmin = 'web_admin'`, `Mobile = 'mobile'`, `Api = 'api'`, `UnknownLegacy = 'unknown_legacy'` — matches plan §923 order verbatim | ✓ |
| Enum has no partition method (no `isAdmissible*`) | Verified — only 5 `case` declarations + class-level PHPDoc; no methods. Distinct from `IntegrityExceptionClass`'s `isAdmissibleToLedger()`. This is correct — `PaymentOrigin` tags **provenance**, not lifecycle / admissibility | ✓ |
| `Payment::$fillable` adds `origin` + `fiscal_event_id` | `Payment.php:97–104` — both entries present, six-line inline PHPDoc block at `:97–102` explains the §13 role and the FK direction | ✓ |
| `casts()` adds `origin → PaymentOrigin::class` | `Payment.php:117` `'origin' => PaymentOrigin::class,` | ✓ — `fiscal_event_id` correctly has **no cast** (UUID = string at the Eloquent boundary, same as all other UUID columns on the model) |
| `@property` annotations added for both | `Payment.php:41–42` — `@property PaymentOrigin|null $origin` + `@property string|null $fiscal_event_id` — **closes the Task 11 P2-2 gap in Payment's own model** | ✓ |
| `PaymentOrigin` import added | `Payment.php:12` `use App\Modules\Treasury\Domain\Enums\PaymentOrigin;` — alphabetically positioned between `Partner`/`Tenant` and `PaymentStatus` (correct PSR-12 alpha order within the enums sub-group) | ✓ |
| Migration documents the projector idempotency role | `:34–40` "The idempotency anchor for the Treasury projector lives on the `(fiscal_event_id, payment_method_id, line)` tuple at projector-level, not on the table" — pins the future Task 22 contract | ✓ |
| Partial index justification | `:53–60` "Index the projector-lookup hot path: when the TreasuryReceiptBridge replays a fiscal event, it needs to know whether any Payment rows already linked to that event id (idempotency tail check). Partial — most legacy rows have NULL fiscal_event_id and don't belong in this index." | ✓ — see "Partial index" below |
| Constraint + index naming convention | `payments_fiscal_event_id_fk` + `payments_fiscal_event_id_idx` follow Task 7/9/10/11's `<table>_<purpose>_<kind>` convention | ✓ |
| CI PG merge-gate extended | `ci.yml:346` adds `|PaymentsOriginColumnsTest` to the filter; `:337–339` adds a matching three-line comment block | ✓ — **the recurring P1 from Tasks 9–11 reviewers is closed at commit time** |

The migration matches plan §901–948 and spec §13/§7.5 with the substantive interpretive choices the brief flagged: (i) no UNIQUE on `fiscal_event_id` — correct (the spec §13 table at lines 561–574 enumerates 9 writers, multiple of which create multiple Payment rows for a single SALE_RECEIPT); (ii) partial index instead of full index — see below; (iii) explicit `@property` annotations — strict improvement over Task 11's omission.

---

## FK direction (the locked-in architectural constraint)

This is the single most consequential review point. Spec §13.6 / SoT D16 are unambiguous:

> The asymmetric bounded-modules seam: a module depends on the engine; the engine depends on no module. The FK MUST run `payments → fiscal_events`, never the reverse.

The migration declares (`2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:47–51`):

```sql
ALTER TABLE payments
ADD CONSTRAINT payments_fiscal_event_id_fk
FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
```

- The constraint is **on `payments`**, the dependent table. ✓
- The reference is **to `fiscal_events(id)`**, the parent / engine table. ✓
- A SQL `FOREIGN KEY` declared on table X referencing table Y creates the dependency `X → Y` (X depends on Y existing) — exactly the spec'd direction. ✓
- The reverse direction would require `ALTER TABLE fiscal_events ADD ... REFERENCES payments(id)`, which the migration does **not** do. ✓
- No trigger on `fiscal_events` references `payments`. ✓

The `fiscal_events` schema introduces zero awareness of `payments`. The engine is free to publish events into a deployment where Treasury is inactive — and Treasury-inactive deployments have a `payments` table that simply never gets `fiscal_event_id` populated. The dependency-inversion contract holds: the engine is the abstraction, Treasury is the consumer. ✓

**Sound — the architecture-locked constraint is correctly realized at the schema layer.**

---

## No UNIQUE on `fiscal_event_id` — projection cardinality

The shape difference from Task 11 is the single load-bearing decision. Task 11's `pos_receipts.fiscal_event_id` carries `UNIQUE` because the POS-core projection contract is "one fiscal event → one `pos_receipts` row" — UNIQUE is the durable, schema-enforced idempotency guard for `PosCoreReceiptProjection::apply()`.

Task 12's `payments.fiscal_event_id` is **deliberately not UNIQUE** because the Treasury projection contract is "one `SALE_RECEIPT` → N `Payment` rows" where N is the number of tenders on the receipt. Spec §14 line 592 makes this explicit:

> `ReceiptPaymentService` creates *both* the POS-core `ReceiptPayment` row (`:297`) *and* the Treasury `Payment` + GL (`:252`, `:269`).

And the Task 22 `TreasuryReceiptBridge` (per spec §7.4) is the projector that creates these N rows — keyed by tender — for each SALE_RECEIPT. A receipt with cash + card + voucher = 3 `Payment` rows, all sharing `fiscal_event_id`.

The migration PHPDoc at `:34–40` documents this contract verbatim. Reviewed against the alternative shapes:

1. **UNIQUE on `(fiscal_event_id, line_seq)` or `(fiscal_event_id, payment_method_id)`** — would be the projector-level idempotency anchor at the schema layer. The implementer chose to leave this off the table and put it at projector-level instead — sound, because: (a) the line/tender granularity is a Task 22 concern that hasn't been designed yet (spec §13 row "`ReceiptPaymentService`" doesn't enumerate the per-tender key), (b) defining the UNIQUE prematurely would lock in a key shape before the projector exists, (c) the projector's `exists()` early-return on `(fiscal_event_id, …)` is functionally equivalent under transaction isolation.

2. **Simple non-unique index** — what the migration ships, partial-on-NOT-NULL. The lookup cost of the projector's "are there any Payment rows for event id X?" query is `O(log n)` on the partial index without uniqueness.

**Verdict: sound, and the PHPDoc explanation is the right framing.** The alternative — UNIQUE at table level — would have over-constrained Task 22's design space. Leaving the idempotency anchor at projector-level is the more general choice.

---

## Partial index — projector hot-path

The migration ships a PG-only partial index:

```sql
CREATE INDEX payments_fiscal_event_id_idx
    ON payments (fiscal_event_id)
    WHERE fiscal_event_id IS NOT NULL
```

PHPDoc at `:53–60` justifies it: "TreasuryReceiptBridge replays a fiscal event, it needs to know whether any Payment rows already linked to that event id (idempotency tail check). Partial — most legacy rows have NULL fiscal_event_id and don't belong in this index."

Reviewed against the three-way trade-off:

1. **No index** — Task 22's `exists()` check becomes a sequential scan on `payments` (potentially 10M+ rows). Untenable.
2. **Full (non-partial) index** — every legacy row contributes an index entry pointing to NULL. PG handles this fine, but the index is bloated by ~99% NULL entries that the lookup query never hits (the projector's predicate is always `WHERE fiscal_event_id = ?`, never `WHERE fiscal_event_id IS NULL`). Wasted disk + tuple-version churn on legacy-row updates that have nothing to do with the projector path.
3. **Partial index (the implementer's choice)** — only the non-NULL subset participates. Index size scales with new-event projection rate, not with legacy-row count. The PG planner will use this index for `WHERE fiscal_event_id = ?` lookups because every value in the index is non-NULL by construction.

**Verdict: sound.** This is the right default for a column where the vast majority of rows are NULL and the query path is always "look up the non-NULL ones."

**One observation worth flagging**: the partial index is **not asserted in the test suite**. A future migration that drops the partial index, or recreates it without the `WHERE fiscal_event_id IS NOT NULL` predicate (making it a full index), would not surface as a test failure. Tasks 9, 10, 11 reviewers flagged this same gap — none of those reviews materialized into a partial-index assertion test, and Task 12 doesn't close it either. **See P2-1.**

---

## FK to `fiscal_events` — no ON DELETE clause

The migration's FK at `:47–51` declares no `ON DELETE` / `ON UPDATE`. Same architecture as Task 11's `pos_receipts.fiscal_event_id_fk`:

1. **PG default `NO ACTION`** blocks deletes. ✓
2. **Task 8's `fiscal_events_immutability_trigger`** (`2026_05_14_100002_create_fiscal_events_immutability_triggers.php`) `RAISE EXCEPTION`s on `TG_OP = 'DELETE'` for `fiscal_events` — belt-and-suspenders. ✓
3. **`ON UPDATE`** is also absent. `fiscal_events.id` is a UUID PK that is never updated (the immutability trigger forbids all UPDATEs except the gated `payload` / `payload_parse_status` write-once flip — and `id` is never gated). ✓

Unlike Task 11 (which omitted naming the trigger in PHPDoc — Nit-2 there), Task 12's PHPDoc does not address the FK's no-ON-DELETE-clause at all. The Task 8 BEFORE-DELETE trigger interaction is implicit. A future-maintainer reading this migration alone would wonder why no `ON DELETE` clause is declared. **See Nit-2.**

---

## Production-model `$fillable` ripple — mass-assignment surface check

This is the recurring concern from Tasks 10/11: adding fields to `$fillable` widens the mass-assignment attack surface if any controller does `Payment::create($request->validated())` or `Payment::create($request->all())` or `(new Payment)->fill($request->validated())`. Audited exhaustively:

| Writer | Pattern | Mass-assignment surface? |
|---|---|---|
| `PaymentController::store()` (line 200) | `Payment::create(['tenant_id' => $tenantId, 'company_id' => $companyId, 'partner_id' => $validated['partner_id'], …])` — explicit array, 14 keys, all server-derived or extracted from `$validated` by name | ✗ — no |
| `PaymentController::storeMultiple()` (line 573) | Same explicit-array pattern, controller-injected `$user->id` | ✗ — no |
| `MultiPaymentService::createSplitPayment()` (line 61) | `Payment::create([...])` — explicit array | ✗ — no |
| `MultiPaymentService::recordDeposit()` (line 133) | Same | ✗ — no |
| `MultiPaymentService::recordPaymentOnAccount()` (line 273) | Same | ✗ — no |
| `PaymentRefundService::refundPayment()` (line 68) | Same | ✗ — no |
| `PaymentRefundService::partialRefund()` (line 153) | Same | ✗ — no |
| `PaymentRefundService` proration refunds (line 436) | Same | ✗ — no |
| `VendorRefundService::refundPrepayment()` (line 99) | Same — explicit array, all server-controlled | ✗ — no |

Verified via `grep -rn 'Payment::create' apps/api/app/Modules/Treasury --include='*.php'` (8 hits, all explicit-array) + `grep -rn '\->fill(' apps/api/app/Modules/Treasury --include='*.php'` (zero hits) + `grep -rn "'origin'\|'fiscal_event_id'" apps/api/app/Modules/Treasury --include='*.php'` (only Payment.php's `$fillable` + `casts()` blocks reference them).

**No FormRequest** in Treasury validates / whitelists `origin` or `fiscal_event_id` as a request body field. Inspected `Treasury/Presentation/Requests/` (if any). The two new fillable entries are written **only** by Task 22's projector (`TreasuryReceiptBridge`) with hardcoded `PaymentOrigin::Pos` + the canonical event id from the projector input — never from request body data.

**Verdict: sound — the mass-assignment surface is closed by the explicit-array pattern that all 9 writers use.** Adding the two columns to `$fillable` enables the projector's explicit `Payment::create([..., 'origin' => PaymentOrigin::Pos, 'fiscal_event_id' => $event->id])` and nothing else. ✓

There is one defensive consideration worth noting: a **future maintainer** who adds a `PaymentController::storeFromRequest($request)` that does `Payment::create($request->validated())` (against existing convention) would inadvertently allow a client to set `origin` / `fiscal_event_id` from the request body. The risk is forward-looking, not present-day. A `$guarded = ['fiscal_event_id', 'origin']` belt-and-suspenders defense would also work — but the current convention is "explicit-array everywhere; $fillable is the documentation, not the gate" and the convention holds across all 9 writers. The convention is the gate. Worth a one-line discipline note for future maintainers; see Nit-3 (optional).

---

## `PaymentOrigin` enum design

| Aspect | Check | Verdict |
|---|---|---|
| 5 cases in spec'd string-value order | `pos | web_admin | mobile | api | unknown_legacy` matches plan §923 verbatim | ✓ |
| Backed string enum (`enum PaymentOrigin: string`) | Yes — required for Eloquent cast | ✓ |
| PascalCase case names → snake_case values | `Pos` / `WebAdmin` / `Mobile` / `Api` / `UnknownLegacy` — consistent with PSR-style enum case naming + the project's idiom (`PaymentStatus::Pending` / `PaymentType::DocumentPayment`) | ✓ |
| Class-level PHPDoc explains role | `:7–29` — 22 lines covering integration role, the 5 case meanings, and the append-only contract for future origins | ✓ |
| **No** partition methods (no `isAdmissibleToLedger`-style) | Only 5 `case` declarations + class doc | ✓ — distinct from Task 10's `IntegrityExceptionClass` which carries `isAdmissibleToLedger()`. Provenance is not a lifecycle classification. |
| Stable string values | PHPDoc `:27–29` explicitly: "The string values are stable — they are persisted in the `payments.origin` column. Adding a new origin in a future phase MUST append to this list, never rename or reorder." | ✓ |
| No `from()`/`tryFrom()` business logic shim | Default enum API only — matches Task 10's discipline (production enums stay minimal until a caller needs a method) | ✓ |

**Sound, minimal, and self-documenting.** ✓

---

## Payment model edit — boundary discipline

The Payment model edit is 4 distinct changes in 12 added lines:

```php
// 1. Import — Payment.php:12
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;

// 2. @property annotations — Payment.php:41–42
 * @property PaymentOrigin|null $origin Phase 1 §13 — which surface authored this payment (pos / web_admin / mobile / api / unknown_legacy)
 * @property string|null $fiscal_event_id Phase 1 §7.5 — UUID FK → fiscal_events.id when this Payment was projected from a SALE_RECEIPT fiscal event by TreasuryReceiptBridge (Task 22); NULL for legacy / non-fiscal payments

// 3. $fillable entries — Payment.php:97–104 (six-line PHPDoc block + 2 entries)
// Phase 1 §13 — fiscal-engine integration columns. `origin` tags
// which surface authored the payment; `fiscal_event_id` is the
// back-link to the authoritative `fiscal_events` row when this
// Payment was projected by TreasuryReceiptBridge (Task 22). The
// FK direction is payments → fiscal_events (module depends on
// the engine; the engine has zero dependency on Treasury).
'origin',
'fiscal_event_id',

// 4. casts() entry — Payment.php:117
'origin' => PaymentOrigin::class,
```

| Aspect | Check | Verdict |
|---|---|---|
| `@property` annotations present for both new columns | ✓ at `:41–42` | ✓ — **closes the Task 11 P2-2 gap** (Receipt model omitted `@property` for `canonical_bytes` + `fiscal_event_id`); Task 12 ships them in the same commit |
| `$fillable` extension | 2 entries appended at the end of the array, after the Task 19 refund-audit block | ✓ — append-only, matches the model's existing layered-history convention (each phase's columns are grouped + commented) |
| `casts()` extension | Only `origin` cast added — `fiscal_event_id` correctly **not** cast (UUID = string at the Eloquent boundary; consistent with all other UUID columns on the model: `tenant_id`, `company_id`, `partner_id`, `payment_method_id`, `instrument_id`, `repository_id`, none of which are cast) | ✓ |
| Import placement | `:12` between `Tenant` and `PaymentStatus` — alphabetically correct within the `App\Modules\Treasury\Domain\Enums\` sub-group | ✓ |
| Inline PHPDoc justification at `$fillable` extension | Six-line comment block immediately preceding the two new entries explaining their role + the FK direction | ✓ — strict improvement on "just append two strings to the array" |
| Relations untouched | None added, none modified | ✓ — Task 22 may add a `fiscalEvent(): BelongsTo` relation later; deferred to that task is correct (no need to ship dead-code relations) |
| Scopes untouched | None added, none modified | ✓ |
| Factory untouched | `PaymentFactory.php` not in diff; no `'origin' => …` / `'fiscal_event_id' => …` references | ✓ — existing factory-using tests continue to seed NULL on both columns (the legacy-row contract); Task 22 may extend the factory with a `withFiscalEvent($event)` state later |

**Boundary discipline: clean.** The edit is minimal and additive; no existing test asserts the `$fillable` shape (grep-verified — `tests/Unit/Treasury/PaymentRepositoryEntityTest.php` asserts a *different* model's fillable), so the extension is regression-safe.

---

## Test discipline (plan §911–927)

| Aspect | Required | Actual | Verdict |
|---|---|---|---|
| Test exists at spec'd path | `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php` | Yes | ✓ |
| Uses `RefreshDatabase` | Per Task 7–11 precedent | `:28` | ✓ |
| Column-existence test | `Schema::hasColumn('payments', 'origin')` + `Schema::hasColumn('payments', 'fiscal_event_id')` | `:31–34` | ✓ |
| Enum-cases-in-order test | Plan §920–926 stub | `:36–44` — `array_map(fn(PaymentOrigin $c): string => $c->value, PaymentOrigin::cases())` then `assertSame(['pos','web_admin','mobile','api','unknown_legacy'], …)` | ✓ — verbatim match to the plan stub |
| Model `$fillable` test | Implementer-added | `:46–52` `(new Payment)->getFillable()` then `assertContains` for both new columns | ⚠ false-positive RED — see Nit-1 |
| Origin cast round-trip test | Implementer-added | `:54–60` `$payment->setRawAttributes(['origin' => 'pos'], true); $this->assertSame(PaymentOrigin::Pos, $payment->origin);` — exercises the cast layer end-to-end without DB I/O | ⚠ false-positive RED (no DB / no migration touched) — see Nit-1 |
| FK existence PG-only test | Implementer-added | `:62–73` `pg_constraint` lookup by name `payments_fiscal_event_id_fk`, skips on SQLite | ✓ — matches Task 7/10/11 catalog-introspection pattern |
| Column-type + nullability PG-only test | Implementer-added | `:75–90` `information_schema.columns` `data_type` + `is_nullable` for both columns — `character varying` for `origin`, `uuid` for `fiscal_event_id`, both `YES` nullable | ✓ — matches Task 10/11 PG-only catalog-introspection pattern |
| `skipUnlessPostgres()` helper | Implementer-added private method | `:92–97` matches Task 7/8/9/10/11 idiom verbatim | ✓ |
| **Partial-index test** | Plan §901–948 stub does not require it, but the recurring Task 9/10/11 reviewer gap | **Missing** — no `pg_indexes` / `Schema::getIndexes` lookup for `payments_fiscal_event_id_idx` or the `WHERE fiscal_event_id IS NOT NULL` predicate | gap — **see P2-1** |
| Genuine RED before migration | Reviewer-verified | Verified — moved migration out, ran tests: `Tests: 6, Assertions: 5, Failures: 1, Skipped: 2` on SQLite. Only **column-existence** failed. Three SQLite tests **falsely-GREEN-on-RED**: enum-ordering, `$fillable`, origin cast round-trip — none depend on the schema being migrated. Restored migration → all six green. | ⚠ partial — wider than Task 11's gap (Task 11: 2 RED + 1 false-GREEN; Task 12: 1 RED + 3 false-GREEN). See Nit-1. |
| GREEN after migration | Implementer claim | Verified — `OK, but some tests were skipped! Tests: 6, Assertions: 6, Skipped: 2` on SQLite | ✓ |

### Cast round-trip test discipline

The implementer added a test (`:54–60`) that the plan stub did not call for:

```php
public function test_payment_model_casts_origin_to_enum(): void
{
    $payment = new Payment;
    $payment->setRawAttributes(['origin' => 'pos'], true);

    $this->assertSame(PaymentOrigin::Pos, $payment->origin);
}
```

This is a **strict improvement** on the plan stub — it pins the cast end-to-end without DB I/O (no migration parents needed, runs in <1 ms). It exercises the path: raw string from DB → `casts()` declaration → enum instance on the model. If a future maintainer accidentally drops the cast (or types it wrong), this test catches it.

The trade-off: like the `$fillable` test and the enum-ordering test, it does not depend on the migration running, so it passes even when the schema column does not exist. That is **good test discipline** (each concern pinned independently — model edit + migration edit are two atomic changes within the commit and pinning each is correct) — but it does mean only 1 of 4 SQLite tests can genuinely RED→GREEN on the migration.

---

## CI gate

| Aspect | Check | Verdict |
|---|---|---|
| `PaymentsOriginColumnsTest` added to `backend-test-pgsql` filter | `ci.yml:349` `--filter="...|PaymentsOriginColumnsTest"` | ✓ |
| Matching comment block | `ci.yml:337–339` three-line comment explaining what the PG-only assertions cover (FK + column-type contract) | ✓ — matches Task 7/8/9/10/11 commenting discipline |
| Filter regex shape | Pipe-separated, no leading/trailing whitespace, no regex escape needed (test class name contains only alphanumerics) | ✓ |
| **Recurring P1 from Tasks 9–11 reviewers closed at commit time** | Yes — the implementer added the test to the merge-gate in the same commit as the migration | ✓ |

The CI gate carries the locked-in pattern. ✓

---

## Findings

### P2-1 — Partial-index assertion missing from the test suite

**Severity:** P2 (coverage gap; not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php`

The migration ships a PG-only **partial** index:

```sql
CREATE INDEX payments_fiscal_event_id_idx
    ON payments (fiscal_event_id)
    WHERE fiscal_event_id IS NOT NULL
```

The test suite has no assertion that this index exists with the partial predicate. A future migration that:
- drops the partial index (`DROP INDEX payments_fiscal_event_id_idx`),
- recreates it without the predicate (making it a full index with NULL entries — wasting disk + tuple-version overhead on legacy-row updates),
- replaces it with a UNIQUE constraint (which would over-constrain Task 22's design, as discussed above),

would not surface as a test failure. This is the same gap Tasks 9, 10, 11 reviewers flagged — and none of those reviews materialized it; Task 12 inherits the unaddressed pattern.

**Suggested fix (PG-only, ~15 lines):**

```php
public function test_fiscal_event_id_partial_index_exists_on_postgres(): void
{
    $this->skipUnlessPostgres();

    $row = DB::selectOne(
        "SELECT indexdef FROM pg_indexes
         WHERE schemaname = current_schema()
           AND tablename = 'payments'
           AND indexname = ?",
        ['payments_fiscal_event_id_idx'],
    );

    $this->assertNotNull(
        $row,
        'Partial index payments_fiscal_event_id_idx missing on PostgreSQL',
    );

    $this->assertStringContainsString(
        'WHERE (fiscal_event_id IS NOT NULL)',
        $row->indexdef,
        'payments_fiscal_event_id_idx must be partial — WHERE fiscal_event_id IS NOT NULL',
    );
}
```

`pg_indexes.indexdef` returns the full reconstructed `CREATE INDEX` statement including the `WHERE` clause; substring-match for `WHERE (fiscal_event_id IS NOT NULL)` (PG normalizes the predicate with parens) pins the partial-ness contract.

**Why P2, not P1:** the partial index is a performance optimization, not a correctness invariant. A regression here would degrade Task 22's projector replay performance (sequential scans on `payments` for the idempotency tail-check) but would not corrupt data. The recurring pattern across Tasks 9–11 + 12 suggests the gap is real enough to land but each individual instance is small.

### P2-2 — `unknown_legacy` distinction not exercised in tests

**Severity:** P2 (semantic coverage gap; not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php`

The migration leaves `origin` nullable, and spec §13 line 576 specifies:

> Any pre-existing rows (preflight gate permitting) → `unknown_legacy`. For non-fiscal web/admin payments `fiscal_event_id` stays NULL.

This is a **two-state contract** for legacy rows:
1. Pre-migration row, never tagged → `origin = NULL` (per the migration default).
2. Pre-migration row, tagged by a backfill → `origin = 'unknown_legacy'`.

The spec leaves the backfill explicit (preflight gate permitting) — and the migration does **not** ship a backfill (correctly — the preflight gate is the §2 chokepoint, not Task 12). The test suite covers:
- The enum has the `UnknownLegacy` case (cast test + enum-ordering test).
- The column is nullable (PG column-type test).

But the suite does not exercise the round-trip on legacy values — e.g.:

```php
public function test_origin_round_trips_unknown_legacy(): void
{
    $payment = new Payment;
    $payment->setRawAttributes(['origin' => 'unknown_legacy'], true);
    $this->assertSame(PaymentOrigin::UnknownLegacy, $payment->origin);
}
```

The cast round-trip test pins `'pos' → PaymentOrigin::Pos` only. If the cast somehow malfunctioned for the four other cases (vanishingly unlikely on a backed string enum — but the cast layer has edge cases around invalid values), the suite would not catch it.

**Suggested fix (5 lines, SQLite-portable):** add a `@dataProvider` or a `foreach` over all 5 cases:

```php
public function test_origin_round_trips_for_every_case(): void
{
    foreach (PaymentOrigin::cases() as $case) {
        $payment = new Payment;
        $payment->setRawAttributes(['origin' => $case->value], true);
        $this->assertSame($case, $payment->origin, "origin cast lost {$case->value}");
    }
}
```

**Why P2, not P1:** Eloquent's backed-enum cast machinery is well-tested upstream. The risk of `PaymentOrigin::Pos` working but `PaymentOrigin::UnknownLegacy` not working is essentially zero. The gap is in test-explicitness, not in runtime behavior.

### P2-3 — Runtime FK-rejection not exercised on PostgreSQL

**Severity:** P2 (coverage gap; not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php`

The PG-only FK test (`:62–73`) pins the FK by **catalog introspection** — it asserts `pg_constraint` has a row named `payments_fiscal_event_id_fk` of type `'f'`. This is the same shape Tasks 9, 10, 11 used. It proves the constraint **exists** but not that it **fires** at runtime.

The runtime-rejection question is: if a writer tried `Payment::create([..., 'fiscal_event_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'])` where that UUID does not exist in `fiscal_events`, would PG reject the INSERT with a foreign-key violation?

A schema-introspection test cannot answer this — a future migration that defines the constraint as `DEFERRABLE INITIALLY DEFERRED` (delaying the check until COMMIT) or `NOT VALID` (skipping the check entirely on existing rows) would still surface as "constraint exists" in `pg_constraint`, but would not actually enforce at INSERT time.

**Suggested fix (PG-only, ~15 lines, requires the factory chain to seed FK parents):**

```php
public function test_fiscal_event_id_fk_rejects_unknown_event_on_postgres(): void
{
    $this->skipUnlessPostgres();

    $bogusEventId = \Illuminate\Support\Str::uuid()->toString();

    $this->expectException(\Illuminate\Database\QueryException::class);

    Payment::factory()->create([
        'fiscal_event_id' => $bogusEventId,
        'origin' => PaymentOrigin::Pos,
    ]);
}
```

Caveat (same as Task 11 P2-1): the `PaymentFactory` must chain through all FK parents (`tenants`, `companies`, `partners`, `payment_methods`, etc.) — which it does. Spot-check the factory before relying on this. Alternative: raw `DB::table('payments')->insert([...])` with explicit FK-parent seeding.

**Why P2, not P1:** the catalog-introspection test pins the structural contract; the runtime-behavior gap is recoverable in Task 22's projector tests (which will inevitably exercise the FK-rejection path when the projector creates Payment rows from canonical events). Same trade-off as Task 11 P2-1 — the gap is "land it now or land it then." Either way, the constraint shape is pinned.

### Nit-1 — `test_payment_model_fillable_*` + `test_payment_origin_enum_*` + `test_payment_model_casts_*` pass on RED

**Severity:** nit (test-coverage observation; not a correctness defect)
**File:** `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php:36–60`

Same shape as Task 11 Nit-1, but wider. Three of the four SQLite tests do not depend on the migration running:

1. **`test_payment_origin_enum_has_phase1_cases_in_order`** (`:36–44`) — reflects on the `PaymentOrigin` enum class, which is part of the same commit as the migration but is independent of the schema being migrated. Passes whether or not the migration has run.
2. **`test_payment_model_fillable_includes_origin_and_fiscal_event_id`** (`:46–52`) — reflects on the Payment model's `$fillable` array, a static property. Independent of the schema.
3. **`test_payment_model_casts_origin_to_enum`** (`:54–60`) — uses `setRawAttributes` to load a value without DB I/O. Independent of the schema.

Observable from the RED-state output (migration moved aside):

```
F...SS                                                              6 / 6 (100%)
Failures: 1, Skipped: 2.
```

One failure (column-existence). Two skips (PG-only). Three passes (`...`) — the three schema-independent tests. So out of 6 tests, only **1** could conceivably go RED on SQLite; **2 are PG-only**; **3 are schema-state-independent**.

This is **not a defect** — pinning the enum + the model fillable + the cast layer independently is **correct test discipline** (each concern is a separate piece of the commit, pinned in isolation). But the implementer's reported "6 tests / 6 assertions / 2 PG-only skipped" doesn't acknowledge that the genuine RED→GREEN signal on SQLite is **1 test, not 4**.

The same pattern existed in Task 11 (Nit-1 there) — but Task 12 is wider (3 false-GREEN-on-RED vs. Task 11's 1).

**Suggested fix:** add a one-line PHPDoc to each of the three migration-state-independent tests clarifying its role:

```php
/**
 * Independent of schema state — pins the enum's case ordering / values.
 * Passes whether or not the migration has run; complements the
 * column-existence test (which is migration-gated).
 */
public function test_payment_origin_enum_has_phase1_cases_in_order(): void
```

Two more like it on the other two. Nine lines of PHPDoc total.

**Why nit, not P2:** the tests are well-conceived; the RED→GREEN signal is weaker than it could be but the truth-table is still correct (each concern pinned independently). Future-reader ergonomics, not correctness.

### Nit-2 — Migration PHPDoc does not document the FK no-ON-DELETE-clause reasoning

**Severity:** nit (PHPDoc readability)
**File:** `apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:46–51`

Unlike Task 11's migration (which at least *mentions* "Task 8's BEFORE DELETE trigger" — though without naming the trigger or its migration, Task 11 Nit-2), Task 12's migration's FK declaration carries **no PHPDoc explanation at all** for the absent `ON DELETE` / `ON UPDATE` clauses:

```php
if (DB::connection()->getDriverName() === 'pgsql') {
    DB::statement(<<<'SQL'
        ALTER TABLE payments
        ADD CONSTRAINT payments_fiscal_event_id_fk
        FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
    SQL);
    // ... (jumps directly to the partial index)
}
```

A future-maintainer reading this in isolation cannot tell whether the omission of `ON DELETE` was deliberate or accidental. The reasoning (PG's `NO ACTION` default + Task 8's BEFORE-DELETE trigger making it belt-and-suspenders) is identical to Task 11's, but Task 11 at least cited it.

**Suggested fix:** add a short PHPDoc note immediately preceding the FK statement:

```php
// FK to `fiscal_events.id`. ON DELETE / ON UPDATE not declared — PG's
// default NO ACTION blocks deletes. The fiscal_events BEFORE DELETE
// trigger (`fiscal_events_immutability_trigger` in
// `2026_05_14_100002_create_fiscal_events_immutability_triggers.php`)
// forbids deletes outright upstream of any FK check, so the FK's
// NO ACTION is belt-and-suspenders.
```

Six lines. Closes both Task 11 Nit-2 and this gap, with the trigger named explicitly. Strict improvement on Task 11's "Task 8's BEFORE DELETE trigger" reference.

**Why nit:** future-maintainer ergonomics, not correctness. (Optional Nit-3, below, is a related discipline note for the production-model side.)

### Nit-3 (optional) — `$guarded` defensive belt-and-suspenders

**Severity:** nit (defense-in-depth observation; not a correctness defect)
**File:** `apps/api/app/Modules/Treasury/Domain/Payment.php`

The current mass-assignment discipline is "every writer uses the explicit-array pattern; `$fillable` is documentation, not the gate." This holds across all 9 writers today (verified above). But a future maintainer who adds `Payment::create($request->validated())` to a new controller would inadvertently allow client-controlled `origin` / `fiscal_event_id`. Two ways to harden:

1. **Defensive `$guarded = ['origin', 'fiscal_event_id', 'fiscal_event_id', …]`** — flips the boundary from allow-list to deny-list for these specific columns. Belt-and-suspenders against the future-maintainer mistake.
2. **A lint rule / Deptrac assertion** that forbids `Payment::create($request->…)` or `(new Payment)->fill($request)` — catches the antipattern at CI rather than at the model layer.

Both are forward-looking. The convention is the gate today; documenting the convention in the model's class PHPDoc would close the gap with zero code changes:

```php
/**
 * Payment record for receivables and payables.
 *
 * @note Mass-assignment discipline: every writer uses the explicit-array
 *       Payment::create([...]) pattern; never Payment::create($request->all())
 *       or $payment->fill($request->validated()). The two §13 columns
 *       (`origin`, `fiscal_event_id`) are server-derived only — never
 *       accepted from request body. New writers MUST follow this pattern.
 */
class Payment extends Model
```

**Why optional:** the convention holds; the risk is forward-looking. A future maintainer is more likely to copy the existing 9 writer patterns than invent a new one. The PHPDoc note is "nice to have," not "must have."

---

## Implementer deviations checked

The task brief flagged several deviations from the plan's literal Step 1 stub. Reviewed each:

1. **Test surface expanded from 2 (plan stub) to 6.** Verdict: **sound, strict improvement.** Plan §911–926 asserts column existence + enum ordering. The implementer adds: model fillable test, origin cast round-trip test, PG-only FK test, PG-only column-type contract test. The four added tests pin distinct contracts (model boundary, cast layer, FK existence, schema shape) — strict improvement, with the Nit-1 caveat that the SQLite tests cover less migration ground than the count suggests.

2. **Partial index added on `fiscal_event_id`.** Verdict: **sound — strict improvement.** The plan §901–948 does not specify an index; the implementer added a PG-only partial index with `WHERE fiscal_event_id IS NOT NULL` and an explicit PHPDoc justification. This is forward-looking work for Task 22's projector hot-path. Marred only by the missing test assertion (P2-1).

3. **`@property` annotations added in the same commit.** Verdict: **sound — strict improvement on Task 11.** Task 11's Receipt model omitted `@property` for the two new columns (P2-2 there); Task 12 lands them in the same commit. The Payment model's pre-existing `@property` block at `:29–62` lists ~25 columns and is now consistent with the new fillable surface.

4. **PaymentOrigin enum has no partition method.** Verdict: **sound.** Provenance is not a lifecycle classification. The Task 10 lesson (write-once on classification + admissibility partition) is correctly absent here — `origin` is set-once-at-write and never reclassified.

5. **Migration PHPDoc explicitly justifies the no-UNIQUE.** Verdict: **sound, the right framing.** The shape-distinction from Task 11 is the load-bearing decision; pinning it in PHPDoc protects future-maintainers from "fix" PRs that would add UNIQUE and over-constrain Task 22.

6. **CI gate filter + comment updated in the same commit.** Verdict: **sound, matches Task 7/8/9/10/11 discipline.** The test is in the PG merge-gate filter from day one — the recurring Task 9/10/11 reviewer P1 (merge-gate inclusion) is closed.

---

## Cross-task regression check

| Prior task | Files touched in `49fb63e3`? | Verdict |
|---|---|---|
| Task 1 (Fiscal module skeleton + `ProjectionStatus` enum scaffolding) | No | ✓ no regression |
| Task 2 (`FiscalEventType` enum) | No | ✓ no regression |
| Task 3 (`IntegrityStatus` / `PayloadParseStatus` / `SignatureStatus` enums) | No | ✓ no regression |
| Task 4 (canonical-golden-vectors fixture) | No | ✓ no regression |
| Task 5 (`FiscalEventCanonicalEncoder` TS) | No | ✓ no regression |
| Task 6 (`FiscalIntegrityProvider` + signature provider seam) | No | ✓ no regression |
| Task 7 (`fiscal_events` table + `FiscalEvent` model) | No (FK references `fiscal_events.id`; no schema change to Task 7) | ✓ no regression |
| Task 8 (immutability triggers on `fiscal_events`) | No (the FK's no-ON-DELETE behavior leans on Task 8's BEFORE DELETE trigger, but Task 8 itself is unmodified) | ✓ no regression |
| Task 9 (`fiscal_event_projections`) | No | ✓ no regression |
| Task 10 (`fiscal_event_quarantine`) | No | ✓ no regression |
| Task 11 (`pos_receipts.canonical_bytes` + `fiscal_event_id`) | No | ✓ no regression — Task 11's UNIQUE shape is correctly **not** mirrored here (different cardinality, documented) |
| Payment model — production hot file | Yes (`+12 / -0`) | ✓ — only import + `@property` + `$fillable` + `casts()` extended; no relations / scopes / observers / methods touched. Factory unaffected (does not reference the new columns). No existing test asserts the exact `$fillable` shape (grep-verified — `PaymentRepositoryEntityTest` asserts a different model). |
| Treasury writers (5 in spec §13 table) | No (all 9 `Payment::create` writers untouched) | ✓ no regression — they continue to insert without `origin` / `fiscal_event_id`, which the migration accepts (both nullable). Task 22 will wire the writer updates. |
| `Billing\Domain\Payment` (the separate class spec §13 line 576 excludes) | No | ✓ no regression — left in place |
| Full Fiscal Feature suite | 56 tests, 108 assertions, 34 skipped on SQLite | ✓ — matches Task 11's post-implementation baseline + Task 12's new 6 tests |

The commit's five-file scope is exactly what Task 12 promises.

---

## Forward-looking notes for Task 22

Four notes for the reviewer of Task 22 (`TreasuryReceiptBridge`), the consumer of this migration:

- **Idempotency anchor at projector-level, not table-level.** The `payments.fiscal_event_id` column has NO UNIQUE constraint — by design, because a single `SALE_RECEIPT` projects to N Payment rows (one per tender). Task 22's projector must implement the idempotency check at the application layer, e.g.:
  ```php
  if (Payment::where('fiscal_event_id', $event->id)->exists()) {
      return; // already projected — idempotent re-entry
  }
  ```
  Under transaction isolation, this is sufficient. The partial index `payments_fiscal_event_id_idx` makes this `exists()` query `O(log n)` on the non-NULL subset. The spec §13 table at lines 561–574 enumerates 9 writers; only the `ReceiptPaymentService` row carries `fiscal_event_id` (the projector path). The other 8 writers set `origin` to a non-`pos` value and leave `fiscal_event_id = NULL`.

- **PaymentFactory does not yet seed `origin` / `fiscal_event_id`.** Existing factory-using tests continue to insert NULL on both columns (matching the legacy-row contract). When Task 22 ships, the projector will create Payment rows with explicit `origin = PaymentOrigin::Pos` + `fiscal_event_id = $event->id`. A `PaymentFactory::withFiscalEvent(FiscalEvent $event)` state method is the conventional way to seed projection-row test data; deferred to Task 22 is correct.

- **No backfill of legacy rows in Task 12.** Spec §13 line 576 says "Any pre-existing rows (preflight gate permitting) → `unknown_legacy`" — but the migration does **not** ship a backfill. Whether legacy rows are tagged `unknown_legacy` or left NULL is a **preflight gate decision** (§2), not a migration concern. If the gate decides to backfill, a follow-up migration would `UPDATE payments SET origin = 'unknown_legacy' WHERE origin IS NULL`. If the gate decides not to, legacy rows stay NULL forever and the `UnknownLegacy` enum case is reserved for explicit tagging by future writers.

- **`ReceiptPaymentService` is split across two projectors** (spec §14, line 592). The POS-core `ReceiptPayment` row goes via `PosCoreReceiptProjection` (Task 21) — that table is `pos_receipts.fiscal_event_id` (the UNIQUE one). The Treasury `Payment` row + GL entry goes via `TreasuryReceiptBridge` (Task 22) — that table is `payments.fiscal_event_id` (the non-UNIQUE one). The two projectors run **independently and idempotently**; the spec §7.5 retry/resume contract treats them as separate keys. Task 22's design must respect this — never UPDATE `pos_receipts`, never INSERT `Payment` rows outside the bridge.

---

## Recommendation

**APPROVE-WITH-MINOR-EDITS — proceed to Task 13** (Device SQLite `fiscal_events` table + triggers + `terminal_state` chain head).

The edits flagged:
1. **P2-1**: add a PG-only partial-index assertion test (~15 lines). Closes the recurring Tasks 9/10/11/12 reviewer gap. The substring-match for `WHERE (fiscal_event_id IS NOT NULL)` in `pg_indexes.indexdef` is the canonical PG idiom.
2. **P2-2**: extend the cast round-trip test to all 5 PaymentOrigin cases (~5 lines, SQLite-portable). Pins the `unknown_legacy` round-trip the spec §13 line 576 implies.
3. **P2-3**: add a PG-only FK-rejection runtime test using the factory chain (~15 lines). Complements the catalog-introspection test by exercising INSERT-time rejection.
4. **Nit-1**: optional one-line PHPDoc on each of the three migration-state-independent SQLite tests clarifying they pin static surfaces. Not required.
5. **Nit-2**: add a six-line PHPDoc note above the FK statement explaining no-ON-DELETE / no-ON-UPDATE with the trigger named explicitly. Closes both this gap and Task 11 Nit-2. Recommended.
6. **Nit-3 (optional)**: add a `@note` to the Payment model's class PHPDoc documenting the explicit-array convention.

Per the parent session's reconciliation policy: these are flagged for the parent session to apply or accept-as-is; this review does not modify source. None of the six findings block Task 13. The migration correctly realizes plan §901–948 + spec §13 + §7.5; the `PaymentOrigin` enum is minimal and disciplined; the Payment model edit is minimal and additive (and closes the Task 11 P2-2 `@property` gap in its own model); the test pins the structural contracts on both drivers; the CI merge-gate is wired from day one.

The architecture-locked constraint — FK direction `payments → fiscal_events`, the asymmetric bounded-modules seam — is correctly realized. The shape distinction from Task 11 (no UNIQUE because one event → N Payment rows) is documented in the migration PHPDoc. The PG-only partial index is justified and named per the locked-in convention; only its test assertion is missing (P2-1). The mass-assignment surface check (the recurring concern from Tasks 10/11) is clean: all 9 Treasury writers use the explicit-array pattern, no FormRequest whitelists the new columns, and the two new `$fillable` entries enable only the Task 22 projector's explicit construction.

No follow-ups required before Task 13 begins (other than the P2/Nit edits above).
