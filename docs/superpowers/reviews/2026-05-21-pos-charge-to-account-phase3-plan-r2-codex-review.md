# Phase 3 Stage B Implementation Plan R2 Codex Review

Reviewed artifact: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`

Prior Opus review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-opus-review.md`

Locked spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Verdict: APPROVE

## R2 Fix Verification

### 1. Validator/parser matrix is now explicit

Task 2 now names tests for extra top-level keys, missing top-level and nested keys, malformed money, malformed VAT partition, synced and pending customer variants, stale and fresh mirror variants, credit-limit present and absent variants, discount present and absent variants, nullable and populated `buyer` and `references`, nullable `line_items[].non_collected_subtype`, and training versus production limit-exceeded behavior.

This closes the contract-drift risk called out by Opus R1. The plan no longer relies on prose-only validation promises for the locked ACCOUNT_CHARGE matrix.

### 2. Device rule and rejected-flow closure are covered

Task 4 now includes an `ambiguous_alias` discriminated-union rejection case before authoring.

Task 10 now requires full-flow rejection tests for insufficient credit and hard-stale block policy attempts. Both cases explicitly assert that `FiscalEventEngine.append()` is not called and that no `/pos/sync/fiscal-events` sync occurs.

This keeps rejected ACCOUNT_CHARGE attempts outside the fiscal chain and closes the dead-path/rejected-flow gap.

### 3. AR command preserves canonical VAT and line summary

Task 7 now adds `vatBreakdown` and `lineVatSummary` to `CreatePOSChargeJournalEntryCommand`, with an explicit test that the command receives canonical VAT breakdown and line summary.

Task 8 now requires `TreasuryAccountChargeBridge` to copy `vat_breakdown` and `line_items` from `CanonicalPayloadReader::forAccountCharge($event)` into the command. The plan states these arrays are copied from sealed canonical bytes, not recalculated from live product or tax tables.

This preserves the line/VAT evidence the spec requires while retaining the locked AR journal shape: debit CustomerReceivable, debit SalesDiscount when discount exists, credit ProductRevenue, credit VatCollected, and no payment-entry path.

### 4. D16 guard is broadened

Task 6 `AccountChargeD16Test` now rejects imports of:

- `Modules\\Treasury`
- `Modules\\Accounting`
- `Modules\\Document`
- `Modules\\Partner`
- `Modules\\Customer`
- `Modules\\Contact`
- `Modules\\B2B`
- `Modules\\Sales`

This matches the asymmetric bounded-module seam for POS-core. The POS-core receipt projector stays independent of outbound operational modules, while the optional bridges remain behind module activation.

### 5. PG CI filter update is mandatory

Task 6 now requires extending `.github/workflows/ci.yml` with `AccountChargeProjectionTest` in the same commit. The plan explicitly ties this to the new `jsonb` projection table and Fiscal feature projection test.

This addresses the Phase 1 standing pattern that CI gate dependency setup must land in the same commit as the new PG-sensitive test.

## Standing Pattern Review

- Cross-tenant FK safety: plan keeps tenant/company scoping in Treasury bridge and POS projection tasks, and the R2 additions do not weaken it.
- Fail-loud over silent downgrade: parser, validator, bridge, and accounting tasks require typed rejection or exception paths for invalid charge payloads and missing accounts.
- Dead-path rebuild: new parser, projector, bridge, Document/Sales bridge, and chokepoint sentinel all have live caller, provider registration, or CI wiring steps.
- Discriminated-union matrix completeness: Task 4 now includes ambiguous alias in the first-round matrix.
- Contract drift: R2 additions align the plan with the locked spec review outcomes, especially VAT/line summary and rejected-flow behavior.
- Per-method skips: no class-level skips are introduced or requested.
- Constructor injection: plan continues to require provider registration and service constructor injection; no production `app()`, `App::make`, or `resolve()` pattern is introduced.
- D16 bounded modules: POS-core and Fiscal guards remain explicit; bridges are module-gated.

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

```bash
git status --short
git branch --show-current
git log --oneline -3
rg -n "extra_top_level|malformed_money|malformed_vat|ambiguous_alias|vatBreakdown|lineVatSummary|Modules\\\\Customer|Modules\\\\Contact|Modules\\\\B2B|Modules\\\\Sales|PG merge-gate|insufficient_credit|hard_stale|REQUEST-CHANGES|BLOCKER|SalesDiscount" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-opus-review.md
rg -n "TBD|TODO|placeholder|\\.\\.\\.|similar to|appropriate|Use the actual|if .* before implementation|maybe" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
sed -n '260,340p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
sed -n '516,548p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
sed -n '676,760p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
sed -n '788,850p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
sed -n '910,930p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
sed -n '1048,1098p' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md
```

The placeholder scan returned no matches. No runtime test suite was run because this is a docs-only plan review.
