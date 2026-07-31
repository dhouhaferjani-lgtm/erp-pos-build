# Codex round-2 adversarial review — DISPATCH-PLAN-v2-first-tenant-2026-07-31

**Review target:** `docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md` @ `ffd8ca20c`
**Run:** Codex session `019fb8f7-7387-7293-a049-f8ce8806feb2`, completed 2026-07-31T16:24:49Z.
Transcribed and committed by the standing-down orchestrator (`14fe970f0`); per HANDOVER §5b the
successor session owns everything from that verdict onward.
**Adjudication 2026-07-31 (successor orchestrator):** all 8 findings spot-checked against code —
ALL CONFIRMED. Verdict accepted in full; folded into
`docs/handoff/DISPATCH-PLAN-v3-first-tenant-2026-07-31.md`.

# Verdict: REJECT

V2 is materially better, but “every v1 finding is incorporated” is false. The manifests are pairwise disjoint, yet D1 cannot satisfy its contract inside its manifest, Lane C’s specification questions omit several load-bearing branches, and a repository-declared first-tenant migration gate is still absent.

## Critical

### 1. D1 cannot establish provision-at-v3 within its write manifest

D1 promises that a freshly provisioned tenant has a v3 terminal and limits writes to the four seeders plus `TenantProvisioningService` and its test ([v2:100-110](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:100)).

That is not the current creation topology:

- Registration creates a company and a POS-disabled location, calls initialization, and creates no terminal ([TenantProvisioningService.php:137](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:137), [TenantProvisioningService.php:161](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:161), [TenantProvisioningService.php:187](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:187)).
- The actual physical-terminal creation paths omit `fiscal_schema_version` ([TerminalController.php:111](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:111), [TerminalController.php:387](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:387)).
- The web-terminal path also omits it ([TerminalController.php:450](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:450)).
- The database default is still v2 ([2026_05_01_000002_add_fiscal_schema_version_to_pos_terminals.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_01_000002_add_fiscal_schema_version_to_pos_terminals.php:14)).

Putting `Terminal::create()` directly in the Tenant service is not a clean manifest-contained escape hatch: repository rules prohibit direct cross-module model imports and require a public service/contract boundary ([CLAUDE.md:30](/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:30)).

D1 therefore needs one of:

- `TerminalController.php` plus its creation-path tests;
- a new public POS terminal-provisioning service/contract plus tests; or
- a deliberate new migration/default policy plus coverage.

All are outside the present manifest.

### 2. The round-1 “enabled policy” requirement was weakened, not incorporated

Round 1 required an actual provision-at-v3/enabled policy and test ([round-1 review:112](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-07-30-codex-dispatch-plan-review.md:112)). V2 only asks for a “coherent enabled/denomination state” and explicitly leaves cash-rounding enablement blocked ([v2:104](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:104), [v2:160](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:160)).

Fresh payment-setting rows are explicitly created with rounding disabled ([CountryPaymentSettingsSeeder.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/CountryPaymentSettingsSeeder.php:22), [CountryPaymentSettingsSeeder.php:57](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/CountryPaymentSettingsSeeder.php:57)). A test that accepts `enabled=false` does not prove the claimed provision-at-v3-with-rounding policy.

The plan must state and test one concrete policy:

- v3 terminal + rounding deliberately disabled until Lane C and an explicit enable command; or
- v3 terminal + enabled, valid TN denomination at launch.

Currently it claims both directions in different places.

### 3. Lane C’s question list cannot settle the refund/correction design

The v3 chain reframing is genuine, but the specification brief omits four load-bearing decisions.

1. **Offline and unsynced refunds.** The current refund service is explicitly online-only and rejects unsynced originals ([refundSettlementService.ts:4](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/refundFlow/refundSettlementService.ts:4), [refundSettlementService.ts:9](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/refundFlow/refundSettlementService.ts:9)). Lane C never asks whether this remains the launch policy or whether device-authored refunds must work offline.

2. **Atomicity and failure ordering.** Today the device authors and force-syncs approval events, then posts `/return`, then separately mirrors the settled result into local Z accounting ([refundCheckoutStore.ts:331](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/refundCheckoutStore.ts:331), [refundCheckoutStore.ts:357](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/refundCheckoutStore.ts:357), [refundCheckoutStore.ts:374](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/refundCheckoutStore.ts:374), [refundCheckoutStore.ts:391](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/refundCheckoutStore.ts:391)). The spec must define failure behavior for “event authored, settlement rejected” and “settlement committed, local event/Z write crashes.”

3. **VOID is the same unintegrated correction problem.** The server route is deliberately retained alongside returns ([routes.php:137](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/routes.php:137)), but `ReceiptVoidService` mutates the original receipt and reverses effects without authoring a v3 correction event ([ReceiptVoidService.php:71](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:71), [ReceiptVoidService.php:115](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:115)). `SALE_VOID` and `REFUND_RECEIPT` remain reserved but unimplemented device event types ([FiscalEventPayloadRegistry.ts:65](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:65), [FiscalEventPayloadRegistry.ts:84](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:84)). A v3 correction design that covers refunds but not VOID is incomplete.

4. **Mixed-v2 and cross-terminal originals.** Canonical REFUND/VOID requires an original fiscal-event UUID ([FiscalEventEngine.ts:367](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/FiscalEventEngine.ts:367)). The projector explicitly says cross-terminal and legacy originals can be unresolved ([PosCoreReceiptProjection.php:605](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:605)), while its fail-closed guard throws when that resolution fails ([PosCoreReceiptProjection.php:564](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:564)). The spec must define whether those refunds are prohibited, bridged with a legacy reference, or projected by another lookup contract.

Lane C should explicitly require an authoring/settlement state machine, offline policy, VOID policy, legacy/cross-terminal policy, destination-specific mixed-tender rounding, and crash/retry reconciliation.

### 4. New first-tenant blocker: the production migration rehearsal gate is omitted

The repository’s first-tenant migration gate still has two unchecked requirements:

- staging-clone migration dry-run and irreversible-operation review;
- production backup checksum and fiscal-table row counts.

They are explicitly open at [2026-05-12-migration-audit-and-rollback.md:94](/Users/houssamr/Projects/syneriva/apps/erp/docs/qa/2026-05-12-migration-audit-and-rollback.md:94).

D2 covers staging chores only and says not to operate on staging ([v2:119](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:119)). Lane E’s item list does not mention this gate ([v2:124](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:124)). The existing staging runbook merely assumes migrations already ran and explicitly excludes production ([STAGING-DEPLOY-RUNBOOK-2026-07-28.md:5](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/STAGING-DEPLOY-RUNBOOK-2026-07-28.md:5), [STAGING-DEPLOY-RUNBOOK-2026-07-28.md:11](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/STAGING-DEPLOY-RUNBOOK-2026-07-28.md:11)).

This is a new blocker neither round captured. It belongs in Lane E with executor, evidence location, and a hard no-go condition.

## Important

### 5. A2’s implementation instruction is correct, but its TDD fixture cannot prove it

V2 correctly requires per-receipt cash-only subtraction and `COUNT(DISTINCT ...)` ([v2:20](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:20)). However, its proposed single cash-plus-card fixture does not expose either row-duplication class completely.

The query groups by payment method ([SalesReportService.php:181](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:181)). With one cash row and one card row, each group contains one row, so `COUNT(*)` and `COUNT(DISTINCT receipt_id)` both return one. It also does not detect duplicate change subtraction across multiple cash legs.

The RED fixture needs one receipt with at least two rows that land in the same cash report group, plus a non-cash leg. Then it can prove:

- change is subtracted once, not once per cash row;
- transaction count is one, not the number of cash rows;
- the card group receives no change subtraction.

### 6. Lane E is inventoried, not executable

Round 1 required an executed release-readiness lane ([round-1 review:85](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-07-30-codex-dispatch-plan-review.md:85)). V2 adds the label, but only says the orchestrator will surface items and no agent will attempt them ([v2:138](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:138)).

That is acceptable as sequencing, but not yet a closure contract. Lane E needs:

- named human owner per gate;
- required evidence and storage location;
- pass/risk-acceptance criteria;
- explicit “tenant #1 cannot onboard until every applicable row is closed.”

## Minor

### 7. D2’s preflight target is internally ambiguous

D2 says unresolved owner inputs move to a handoff checklist, while targeting `preflight-runbooks.sh` failures “reduced to exactly the owner-input rows” ([v2:124](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:124)). The script scans only `docs/pos-operations` and fails on every unresolved marker ([preflight-runbooks.sh:19](/Users/houssamr/Projects/syneriva/apps/erp/scripts/preflight-runbooks.sh:19), [preflight-runbooks.sh:55](/Users/houssamr/Projects/syneriva/apps/erp/scripts/preflight-runbooks.sh:55)).

Specify whether D2’s acceptance state is:

- zero preflight hits, with owner inputs tracked solely in the handoff checklist; or
- expected non-zero hits that deliberately block until Lane E.

### 8. Lane A is titled “3 defects,” but only two are defects

Item 3 explicitly says it is not live ([v2:16](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:16), [v2:28](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md:28)). Rename the lane to “2 defects + optional hygiene.”

## Incorporation and manifest audit

| Item | Result |
|---|---|
| A1 deletion | Genuinely incorporated. No A1 write remains. |
| A2 subtraction + count | Implementation wording incorporated; test design insufficient. |
| A3 correction | Genuinely incorporated as optional hygiene. |
| B1 parser scope | Genuinely incorporated. The existing helpers already live beside the drift test, so a private-int-map parser fits there ([FiscalPayloadKeyDrift.test.ts:104](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts:104)). |
| Lane C reframing | Genuinely reframed around v3, but its question set is incomplete. |
| Lane D split | Genuinely incorporated. |
| Lane E | Mentioned and sequenced, but not made executable. |
| Write manifests | Pairwise disjoint, but D1 is not sufficient. |

Specific manifest conclusions:

- **Lane A:** sufficient. It changes query semantics without changing the existing DTO shape or route/controller contract ([PaymentMethodBreakdownData.php:13](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/DTOs/Reports/PaymentMethodBreakdownData.php:13), [ReportsController.php:151](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php:151)).
- **Lane B:** sufficient. B1 fits in the existing drift test; B2 can use the existing real-SQLite adapter under the permitted DB test directory ([sqliteTestAdapter.ts:68](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/__tests__/helpers/sqliteTestAdapter.ts:68)); B3 and B4 fit their named files.
- **Lane C:** sufficient only for the spec phase, provided the missing questions above are added.
- **Lane D1:** insufficient for terminal-v3 creation and enabled-policy closure.
- **Four seeders for D1 task 1:** sufficient for the four named entry points. `DemoPharmacySeeder` delegates first-run setup to `ParapharmacySeeder` ([DemoPharmacySeeder.php:341](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DemoPharmacySeeder.php:341), [DemoPharmacySeeder.php:366](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DemoPharmacySeeder.php:366)), but skips the parent on rerun ([DemoPharmacySeeder.php:372](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DemoPharmacySeeder.php:372)). Therefore `ParapharmacySeeder` covers first run and `DemoPharmacySeeder` must cover rerun; both are legitimately needed.
- **Lane D2:** write scope is sufficient, subject to clarifying the preflight acceptance state.
- **Lane E:** no write manifest is needed for execution, but it does need a concrete closure contract.

Required before redispatch: expand D1’s manifest and choose a single enablement policy; repair A2’s RED fixture; add the missing Lane C decisions; add the production migration rehearsal to Lane E; and make Lane E a hard, evidence-backed onboarding gate.
