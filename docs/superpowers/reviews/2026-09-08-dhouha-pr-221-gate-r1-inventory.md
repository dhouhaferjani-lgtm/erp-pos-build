# Gate r1 — PR #221 "count movements carry the CNT number and link to the counting" (QA-BUG-09 / DEV-QA-077)

- **Reviewer:** inventory-costing-reviewer (adversarial, code-grounded)
- **Date:** 2026-09-08
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-221` (branch `gate/pr-221`, HEAD `88af6df71`, base `origin/dev 5b126daac`)
- **Scope gated:** backend (`Modules/Inventory` listener / domain service / controller), tests, `.github/workflows/ci.yml`, `apps/api/tests/feature-lane-manifest.json`. FE read for blast-radius only.

## VERDICT: **MERGE-WITH-FIXES** — spec ✅, quality APPROVED-WITH-MINORS

No Blocker, no Major. Five Minors, all documentation/CI-hygiene or self-declared follow-ups. Every load-bearing claim in the PR body that I could check was **true**, including the mutation proof, which I reproduced myself.

---

## 1. Idempotency / replay safety — VERIFIED SAFE

**Every reader of the label was enumerated.** `grep -rn "COUNT_REPLAY" app database tests routes config` returns only:
- `StockAdjustmentService.php:80` (the constant itself), `:1310`, `:1330` (fallback), `:70`/`:1424` (comments)
- `ApplyStockAdjustmentsOnCountingCompleted.php:383` (comment)
- `StockMovementDocumentLinkageTest.php:410` (new fallback pin), `CountingMovementReferenceTest.php:44,52` (comments)

**No production code branches on the literal `'COUNT_REPLAY'` anywhere.** Existing staging/production rows keep the label and continue to behave identically — every consumer keys on `(reference_type, reference_id)`, not on the label.

Claim-by-claim:

| PR claim | Verified at | Result |
|---|---|---|
| `appliedGrains()` keys on (reference_type, reference_id) | `apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:316-322` — `->where('reference_type', StockMovementReferenceType::InventoryCounting)->where('reference_id', $counting->id)` | ✅ TRUE — label-independent |
| Legacy `COUNTING:{number}` probe at `:212-221` untouched | `ApplyStockAdjustmentsOnCountingCompleted.php:212-221` (`->where('reference', $reference)->exists()`); `git diff` touches only `:360-410` in this file | ✅ TRUE |

**Cross-path hazard checked and cleared.** Path selection is *per item* and deterministic: `ApplyStockAdjustmentsOnCountingCompleted.php:130` (`if ($item->final_qty_as_of === null)` → legacy, else replay). An item can never switch paths across a queue retry, so a replay-labelled row can never be missed by the legacy `COUNTING:…` probe. The replay path's own guard is `$item->replay_audit !== null` (`:145`), not the label.

**DB backstop is label-independent.** `database/migrations/tenant/2026_08_23_120000_unique_stock_movements_counting_apply.php:15-27` — partial unique on `(reference_id, product_id, location_id[, variant_id]) WHERE reference_type='inventory_counting'`. `reference` is not in the index; the label change cannot collide or mask a duplicate.

**Column width.** `database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:41` — `$table->string('reference')->nullable()` = varchar(255). The `?? $counting->id` UUID fallback (`listener:368`) fits, and matches the legacy fallback at `:88`.

**Other movement-`reference` readers** (all display-only, none keyed):
- `EntryExitNoteController.php:228` `'source_label' => $first->reference ?: …` — grouping is on `(direction, source_type, reference_id, reference_type, location_id)` (`:203-211`), NOT on `reference`, so note grouping is unaffected; only the displayed label improves.
- `CheckCogsCoverageCommand.php:345` `'document_number' => $movement->reference ?? $movement->reference_id` — report display.
- `StockMovementController.php:91` `LOWER(stock_movements.reference) LIKE ? ESCAPE '!'` — the operator search the PR cites; claim ✅ TRUE.
- `RepairPhantomDefaultBatchesCommand.php:433-435`, `BackfillGoodsReceiptsCommand.php:153-155` — key on `reference_type`/`reference_id` only.
- No append-only trigger and no Fiscal-module consumer over `stock_movements` (grep: zero hits in `app/Modules/Fiscal`), so the label is not hash-chained.

## 2. Rule 8 (events immutable) — VERIFIED

`git diff 5b126daac..HEAD --stat` lists **no** file under `Modules/Inventory/Domain/Events/`. Only the *value* passed changed: `StockAdjustmentService.php:1803` and `:1821` now pass `$movementSnapshot->reference` (the persisted row) instead of `self::COUNT_REPLAY_REFERENCE`. The parameter is nullable — `StockMovementRecorded.php:34` `public readonly ?string $reference = null` — so no type hazard. No consumer asserts the literal: the only `StockMovementRecorded` listener is `ChannelServiceProvider.php:29 → DispatchStockChangeToChannels`, which does not read `reference`.

## 3. Signature changes — VERIFIED SAFE (no fatal)

Exhaustive grep across `app/`, `database/`, `tests/`, `routes/`:
- `applyCountResult()` — **one** production call site: `ApplyStockAdjustmentsOnCountingCompleted.php:373` (passes `reference: $countingReference` at `:409`). Test call sites `StockMovementDocumentLinkageTest.php:362,394` both omit the new **optional** last-position `?string $reference = null` (`StockAdjustmentService.php:1325`) — legal.
- `postCountCorrection()` / `postCountOpening()` are **private**; only call sites are `StockAdjustmentService.php:1377` and `:1375`, both updated. The new `string $reference` is required and positioned before the optional closure — no silent default. No runtime-fatal surface.

**PHPStan level 8, three touched classes:** `[OK] No errors` (ran in the worktree with `phpstan.neon`).

## 4. Controller — VERIFIED, with one declared-follow-up caveat

- Counting prefetch is tenant **and** company scoped: `StockMovementController.php:160-165` (`->where('tenant_id', …)->where('company_id', …)->whereIn('id', $countingIds)`), the same shape as the pre-existing Document prefetch at `:136-141`. ✅
- Empty-page short-circuit: `:158-159` `$countingIds->isEmpty() ? new Collection : …`. ✅
- `reference_id` exposed raw: `:231`. ✅ (intentional — the cross-company negative asserts it is reported verbatim)
- `source_document_type` emits the **enum value**, not a literal: `:239` `StockMovementReferenceType::InventoryCounting->value`. ✅ rule 9 honoured (F-2 genuinely fixed).

**Blast radius of the non-DocumentType value (her F-3), measured:** exactly two consumers of the stock-movement payload's `source_document_type` exist, both updated in this PR — `apps/web/src/features/inventory/StockMovementsPage.tsx:360` and `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:255`, both now routed through `movementSourceLinkTypeFromSource()` (`apps/web/src/lib/entityRoutes.ts:149-157`). `packages/shared/types/generated.d.ts:786` and `apps/web/src/types/document.ts:121` are the **DocumentData** payload's field (`DocumentData.php:72,302`) — a different surface, untouched. No mobile/POS consumer (`grep source_document_type apps/mobile apps/pos/src` → zero). `EntityLink` is null-safe (`EntityLink.tsx:24,60-62`) and the `/inventory/counting/:id` route exists behind the same `moduleKey="inventory"` as the movements page (`apps/web/src/routes/index.tsx:1338-1347`). **Contained.** Residual risk is only a *future* consumer doing `DocumentType.tryFrom(source_document_type)` — see Minor-3.

## 5. Tests — RUN AND MUTATION-PROVEN

Run from `.worktrees/pr-221/apps/api` on SQLite (`phpunit.xml:44-45`), targeted classes only:

```
CountingMovementReferenceTest        7 passed (46 assertions)
StockMovementDocumentLinkageTest    15 passed (38 assertions)
CountingFinalizeLockTest             2 passed, 3 skipped (11 assertions)
ReplayFinalizeTest                  12 passed (40 assertions)   [sibling regression check]
```

**Mutation reproduced.** I deleted `->where('company_id', $company->id)` from `StockMovementController.php:162` and re-ran case 4:

```
FAILED … a second company never sees the first companys counting linkage
Failed asserting that '01a081dd-…' is identical to null.
at tests/Feature/Inventory/CountingMovementReferenceTest.php:396
Tests: 1 failed (14 assertions)
```

Exactly the line and shape she reported. File restored with `git checkout --`; `git status --porcelain` clean. **F-1 is genuinely fixed — the negative can go red.**

**Conventions/09 coverage present and data-meaning-asserting:**
- second company — `CountingMovementReferenceTest.php:343-402` (two companies in one tenant, plus a *forced* cross-company FK at `:384-386`, asserting `source_document_id`/`source_document_type` null at `:396-397` and A's number absent from the payload at `:401`)
- second location — `:408-435` (two countings, two distinct references, only the first location `is_default` — `makeLocation(..., isDefault: false)` at `:412`, F-5 fixed)
- re-run / idempotency — `:443-461` (listener fired twice; one movement, reference unchanged, stock level `68.0000`)

No `assertTrue(true)`, no mocked subject, `RefreshDatabase` + `RolesAndPermissionsSeeder` (`:67, :93`), real models throughout. House rule 20 respected: no `CompanyContext` in `setUp()` (`:60-63`), bound explicitly only in the HTTP cases (`:312, :363`).

## 6. CI / manifest — ADDITIVE, CHECKER GREEN

- `.github/workflows/ci.yml:1122` — diffed the `--filter` string programmatically: **200 → 201 class names, zero removed, one added (`CountingMovementReferenceTest`)**, +30 bytes = `CountingMovementReferenceTest|`. Strictly additive; the `/\\(…)::/` anchoring is intact.
- `apps/api/tests/feature-lane-manifest.json:836-837` — Inventory `classes` 126 → 127, `gated_ceiling` 1249 → 1250, with a `raise_note_2026_09_08_qa_bug_09`.
- `php tools/feature-lane-manifest-check.php` → **OK** (`1514 Feature classes in 74 groups; … every --filter entry is anchored and uniquely matched`).

## 7. Precision contract (rule 19) — NOT TOUCHED, CONFIRMED

The whole backend diff is string/UUID plumbing: a label (`?string $reference`), a raw `reference_id` UUID in the payload, and an enum `->value`. Zero arithmetic, zero `bc*`, zero `(float)`, zero scale resolution added or moved. The touched listener already passes explicit `$currencyCode` for its queued context (`:186-189`) — untouched. `WeightedAverageCostService` not in the diff. No FE `parseFloat`/`Number()` introduced (FE diff is JSX branching only).

---

## Findings

### Blocker
None.

### Major
None.

### Minor

**[Minor-1] `.github/workflows/ci.yml:1122` + `apps/api/tests/feature-lane-manifest.json:837` — the new allowlist entry ships without the file's standard explanatory comment, and the manifest note omits the caveat every neighbouring note carries.**
The comment block at `ci.yml:1116-1121` documents only PR #218's `CountingIndexStatusFilterTest`; `CountingMovementReferenceTest` was appended to the filter with no adjacent comment, breaking the file's own precedent. More importantly, the manifest note asserts the class "has a live CI gate on real PostgreSQL" without the caveat the repo states twice for identical entries (`ci.yml:1016-1019` and `:1035-1038`): `backend-test-pgsql`'s condition is `github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || github.base_ref == 'dev' || (push && ref == main)` (`ci.yml:588`) — it does **not** run on `push → dev`, which is the actual promotion path under rule 21. *Why it matters:* the note is what a future session reads when deciding whether the class is protected during a dev promotion. *Fix:* append the two-line comment above the filter and add the `NOT on push->dev` caveat sentence to the manifest note.

**[Minor-2] `apps/api/tests/Feature/Inventory/CountingFinalizeLockTest.php:362` — a fixture edit verified by nothing in CI.**
`'reference' => 'COUNT_REPLAY'` → `'CNT-2026-0009'`. The three cases consuming that fixture are the partial-index cases, **skipped on SQLite** (I observed `3 skipped`), the Inventory lane is parked, and `grep -c CountingFinalizeLockTest .github/workflows/ci.yml` = **0** — the class is in no pgsql allowlist. *Why it matters:* the edit is cosmetic (the class asserts on the unique index, never on the label), so the risk is low, but it is an unverified change to a CI-dark class and arguably rule-4 scope creep. *Fix:* either revert it (nothing requires it) or state in the PR that it is unexercised.

**[Minor-3] `StockMovementController.php:232-241` — `source_document_type` now unions two vocabularies with no glossary declaration.**
The field can now be a `DocumentType` value or `inventory_counting`. `docs/glossary.md` has no row for stock movement / source document (grep: only Lot, Stock level, Account, Purpose, Opening batch nearby), so rule 22 "one surface per concept" has nothing to check the widening against. Blast radius is *verified contained today* (§4), and she declares this as follow-up F-3 herself. *Why it matters:* the next consumer that writes `DocumentType::tryFrom($sourceDocumentType)` gets a silent null. *Fix:* one glossary row naming the field and its two vocabularies, before a third is added.

**[Minor-4] `CountingMovementReferenceTest.php:335-337` vs `:354` — the negative guards `company_id` only; `tenant_id` is untestable by construction.**
Company B is created in the *same* tenant (`makeCompany` at `:105-106` always uses `$this->tenant->id`), so removing `->where('tenant_id', …)` from the prefetch cannot turn any case red. The docblock's "the `company_id` (or `tenant_id`) predicate" phrasing reads as if both are guarded. Defensible under database-per-tenant (a cross-tenant id cannot appear in the same DB), but the wording overstates the proof. *Fix:* one-word docblock correction.

**[Minor-5 / informational] Asymmetry: `StockMovementController.php:136-141`** — the pre-existing Document prefetch still issues `whereIn('id', [])` on pages with no document movement, while the new counting prefetch short-circuits (`:158-159`). Pre-existing, correctly left alone under rule 4; noting so the follow-up lane picks it up.

### Not verified (stated, not asserted)
- **The PostgreSQL leg.** `docker ps` shows no `autoerp_postgres` on 5433 (only `locaplex-test-postgres-*` and `hr-reconcile-*`). I could not reproduce her PG run or the PG mutation. Her SQLite claims all reproduced exactly, and the `locations.code varchar(20)` fixture guard is present in code (`CountingMovementReferenceTest.php:152-154`), which is consistent with a real PG run having happened — but I am not asserting it.
- Vitest / typecheck / ESLint / preflight numbers in the PR body (FE gate not in this scope).

---

## What to fix before merge
Add the missing ci.yml comment and the `NOT on push->dev` caveat to the manifest raise note (Minor-1); everything else is a follow-up lane.
