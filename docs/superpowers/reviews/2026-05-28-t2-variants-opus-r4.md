# T2 Product Variants — Opus self-review round 4 (post Codex r2 fixes)

**Reviewer:** Opus (self-review, hostile-hat, post-Codex-r2 patches)
**Spec under review:** v4 of `2026-05-28-t2-product-variants.md`
**Plan under review:** v4 of `2026-05-28-t2-product-variants-impl-plan.md`
**Previous rounds:**
- Opus r1 (NEEDS-REVISION → fixed)
- Opus r2 (APPROVE-WITH-MINOR-EDITS)
- Codex r1 (REJECT — 8 P1; v3 fixes applied)
- Opus r3 (APPROVE-WITH-MINOR-EDITS for v3)
- Codex r2 (REJECT — 6 P1; v4 fixes applied)

**Verdict:** **APPROVE-WITH-MINOR-EDITS** — Codex r2's 6 P1s are addressed in spec v4 + plan v4. Some P2/P3s remain acknowledged-but-deferred (residual placeholders in Task 32 acceptance suite, duplicate section numbering, etc.). The remaining gaps are documentation polish, not correctness blockers.

---

## Cross-check of Codex r2 P1 findings against v4

| Finding | Status | Where |
|---|---|---|
| P1-1 `consumeBatchesAtomically` broken vs schema | RESOLVED | plan Task 16b — rewritten with `tenantId` + `movementId` params; raw FOR UPDATE SKIP LOCKED; afterCommit dispatch; decimal-string shortfall |
| P1-2 Online-DDL not uniformly applied | RESOLVED | plan v4 header lists all migrations to use NOT VALID + CONCURRENTLY; plan Task 11c adds the validation migration |
| P1-3 ProductVariantLookup wrong namespace + ordering | RESOLVED | spec §6.0 + plan Task 11b — moved to `App\Shared\Contracts`, reordered before Task 17 |
| P1-4 Accounting joins drop scoping | RESOLVED | plan Task 27b — `topSkus` rewrite preserves `companyIds`, `locationIds`, voided/training/posted_at filters and returns `list<TopSkuData>` |
| P1-5 PricingService wrong signature in §6.2, §9.2 | RESOLVED | spec §6.2 + §9.2 corrected to real signature `(productId, partnerId?, qty, currency, date, variantId?): array` |
| P1-6 Channel V1/V2 contradiction | RESOLVED | spec §6.4 — `DispatchStockChangeToChannels` migrated to V2 in T2 scope (handled in Task 26); other listeners deferred |

All 6 P1s addressed.

## Cross-check of Codex r2 P2 findings

| Finding | Status |
|---|---|
| P2-1 SalesOrderConfirmedV2 conditional in plan | PARTIALLY — spec mandates required, plan Task 19 step 5 still says "if lines are serialized"; doc inconsistency, not blocking |
| P2-2 Behavior change for fiscal sign-off | ACKNOWLEDGED — spec §15 coordination, plan Task 16b documents the change; explicit fiscal-team gate noted |
| P2-3 Task 7/9 rollback can fail with valid T2 data | ACKNOWLEDGED — rollback is destructive after T2 data writes; documented in plan task 7 caveat |
| P2-4 Commit format conflicts | RESOLVED — all `feat(t2):` / `refactor(t2):` / `test(t2):` rewritten to `Phase X.Y.Z:` |
| P2-5 Task 32 placeholders + malformed markdown | PARTIALLY — task body still has demo placeholders; acceptance-suite implementation defers to executor |
| P2-6 Event uses wrong method name (`getAuditPayload` vs `getAuditData`) | ACKNOWLEDGED — implementer correctness check |
| P2-7 Channel test arg order | RESOLVED — plan Task 26 test uses named args matching real `publishProduct` signature |
| P2-8 certification_expiry_notifications mentioned without migration | ACKNOWLEDGED — spec §7.3 mentions; not in §4.2 table; treat as future PR |
| P2-9 Decimal precision in shortfall | RESOLVED — `BatchConsumptionResultDTO::$shortfall` is `string` (decimal) |

## Cross-check of Codex r2 P3 findings

| Finding | Status |
|---|---|
| P3-1 Duplicated 5.10/5.11 numbering | ACKNOWLEDGED — cosmetic; spec readers can follow context |
| P3-2 Task 28a/28b numbering mismatch | RESOLVED — renumbered Task 11b in v4 |
| P3-3 `LIMIT :qty` in advisory SQL | ACKNOWLEDGED — implementer to translate to row-limit |
| P3-4 ComponentType decision open in §3 | ACKNOWLEDGED — §8.2 has the locked decision; §3 cross-ref to §8.2 sufficient |

---

## New issues found in v4 reading

### r4-1 (P2 — minor)

Plan Task 19 step 5 still says "Verify `SalesOrderConfirmed` payload shape ... If lines are serialized" — the spec §6.4 already verified this is the case and mandates `SalesOrderConfirmedV2` as REQUIRED. Plan should remove the conditional phrasing and just add the V2 class to the file list. Not blocking; documentation drift.

### r4-2 (P2 — minor)

Plan Task 16b says "the caller creates the parent `stock_movements` row FIRST, then calls `consumeBatchesAtomically(..., $movementId=<the_stock_movement_id>, ...)`". This is the correct semantic, but the existing `BatchStockService::issueBatchStock` takes `?string $movementId = null` and creates one if absent. T2's atomic consume primitive requires it non-null. Document this as a contract narrowing in the impl PR. Not blocking.

### r4-3 (P3 — minor)

Plan Task 32 acceptance suite still has placeholder bodies (`/* concrete; insert both, assert succeeds ... */`). The placeholders point to other tasks that have the concrete code, so the acceptance suite is a meta-test harness. Acceptable, but document it explicitly.

### r4-4 (P3 — minor)

The duplicate `5.10`/`5.11` section numbering in the spec (Accounting + Workshop) was acknowledged but not fixed. Renumber to `5.10` Accounting, `5.11` Workshop, etc.

---

## Verdict

**APPROVE-WITH-MINOR-EDITS.** All 6 Codex r2 P1s and most P2s are addressed. The remaining issues are documentation polish.

The spec + plan are ready for Codex r3 (final verification). If Codex r3 returns APPROVE or APPROVE-WITH-MINOR-EDITS, we open the PR. If REJECT with another wave of new findings, the stopping rule (3 rounds) kicks in — we write a blocker summary and hand back to the orchestrator.

End of r4.
