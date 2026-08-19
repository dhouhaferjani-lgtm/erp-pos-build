# M4 T20 inventory variance account-map proposal

**Status:** APPROVED AND IMPLEMENTED — Option A (`6586` / `7586`)

**Authority:** `ORCHESTRATOR-RULING-2026-08-19-m4-stop-b.md`

**Pinned tree:** `48cebf0f2c1b481c592bf35d478302499f9fda5d`

**Decision owner:** treasury owner / expert-comptable

**Owner ruling:** `TREASURY-RULING-2026-08-19-t20-option-a.md`

**Implementation evidence:** `M4-evidence.md`

**Implementation commit:** `ea1280d21d9e573e4b8f43f8f94ba6ecd6580aa5`

## Owner decision — 2026-08-19

The treasury owner approved **Option A** in full, including the TN/FR/Generic codes, types, parents,
and all 17 `MovementReason` classifications below. The recorded rationale is distinct-line fraud
visibility, zero enum churn against the merged 3C type gate, PCG-defensibility, and reversibility by
template version while the purposes remain dormant.

The approval carries one rider: expert-comptable ratification under OQ-12/H-5 is required before
count-correction posting goes live. M4 may implement the approved templates, backfill, parity proof,
and destructive-loss rerouting; M5 must not make count-correction posting live without that
ratification.

## Decision requested

Ratify **Option A** or **Option B** as one complete TN/FR/Generic map. Rejecting both is also a valid owner disposition, but replacement codes must be supplied before T20 starts. This memo makes no accounting choice.

The six codes below are proposals, not shipped configuration. Each uses an unused code in the current frozen chart fixtures and leaves the three legacy seeders byte-identical.

## Option A — class-7 other-income model

Count shortages and destructive losses debit a dedicated class-65 expense. Count surpluses credit a dedicated class-75 other-income account. This option preserves the current domain typing: `InventoryShrinkageExpense` is `Expense` and `InventoryGainIncome` is `Revenue`.

| Chart | Shrinkage code | Type / parent | Gain code | Type / parent |
|---|---:|---|---:|---|
| TN | `6586` — Écarts d'inventaire — manquants et pertes | expense / `65` | `7586` — Écarts d'inventaire — excédents | revenue / `75` |
| FR | `6586` — Écarts d'inventaire — manquants et pertes | expense / `65` | `7586` — Écarts d'inventaire — excédents | revenue / `75` |
| Generic | `6586` — Inventory Shrinkage Expense | expense / `6000` | `7586` — Inventory Count Gain | revenue / `7000` |

Technical consequence: no `SystemAccountPurpose::expectedAccountType()` change. The existing publish gate accepts these type/purpose pairs.

Presentation consequence: count gains appear as other operating income rather than as a credit correction of purchases. OQ-12/H-5 still governs the separately shipped `CostOfGoodsSold => 603` presentation, but this option does not add count gains to that 603 balance.

## Option B — 603 symmetric debit/credit model

Both shortage and surplus are subdivisions of stock variation. Shortages debit one subdivision and surpluses credit the other. Both accounts remain expense-typed so a surplus is presented as a credit reduction of the stock-variation/purchases family, not as class-7 revenue.

| Chart | Shrinkage code | Type / parent | Gain code | Type / parent |
|---|---:|---|---:|---|
| TN | `6038` — Écarts d'inventaire — manquants et pertes | expense / `603` | `6039` — Écarts d'inventaire — excédents | expense / `603` |
| FR | `6038` — Écarts d'inventaire — manquants et pertes | expense / `603` | `6039` — Écarts d'inventaire — excédents | expense / `603` |
| Generic | `6038` — Inventory Shrinkage | expense / `6030` | `6039` — Inventory Count Surplus | expense / `6030` |

Technical consequence: the current publish gate rejects the gain row. Choosing this option therefore explicitly authorizes changing `InventoryGainIncome` from `AccountType::Revenue` to `AccountType::Expense`, plus updating its type regression. The purpose name may remain business-directional even though the account is expense-typed and normally carries a credit balance.

Presentation consequence and mandatory caveat: OQ-12/H-5 already warns that the shipped `CostOfGoodsSold => 603` model requires expert ruling on compte de résultat / liasse presentation before first filing. Option B extends that caveat to count surpluses and destructive losses. The French ANC describes 603 subdivisions as stock-variation accounts that may carry debit or credit balances and present as corrections to purchases; that does not decide the repository's TN/FR liasse policy. Treasury acceptance must state that the same H-5 review covers the new 6038/6039 subdivisions. Source: [ANC Plan comptable général, version 1 January 2026](https://www.anc.gouv.fr/files/anc/files/1_Normes_fran%C3%A7aises/Reglements/Recueils/PCG_janvier2026/PCG--1er-janvier-2026.pdf).

## MovementReason routing required by F-3

This routing is common to both code options. “Neither” is explicit; it must not silently fall through to COGS or shrinkage.

| MovementReason | Counter-account family | Required behavior after approval |
|---|---|---|
| `GoodsReceipt` | Neither — GR/IR procurement lane | Preserve the existing goods-receipt accounting lane. |
| `CustomerReturn` | COGS reversal | Debit Inventory / credit COGS using the original exit basis. |
| `AdjustmentPositive` | Neither — D-20 deliberate no-GL adjustment | Preserve the buffer exclusion and detector exclusions. |
| `TransferIn` | Neither — balance-sheet transfer | No P&L leg in this seam. |
| `ProductionOutput` | Neither — future production-cost lane | No COGS or shrinkage classification in this wave. |
| `OpeningBalance` | Neither — opening equity lane | Preserve its dedicated opening-balance service. |
| `Delivery` | COGS | Debit COGS / credit Inventory. |
| `SupplierReturn` | Neither — procurement reversal lane | Do not classify as shrinkage or COGS; there is no production writer today. |
| `AdjustmentNegative` | Neither — D-20 deliberate no-GL adjustment | Preserve the buffer exclusion and detector exclusions. |
| `CountCorrection` | Directional shrinkage/gain | Negative delta: debit Shrinkage / credit Inventory. Positive delta: debit Inventory / credit Gain. |
| `TransferOut` | Neither — balance-sheet transfer | No P&L leg in this seam. |
| `Damage` | Shrinkage | Reroute off COGS: debit Shrinkage / credit Inventory. |
| `Expiry` | Shrinkage | Reroute off COGS: debit Shrinkage / credit Inventory. |
| `WriteOff` | Shrinkage | Reroute generic and batch write-off paths off COGS. |
| `Consumption` | Neither — not GL-bearing in the current enum | Do not broaden T20 into an internal-consumption policy decision. |
| `POSSale` | COGS | Debit COGS / credit Inventory. |
| `POSReturn` | COGS reversal | Debit Inventory / credit COGS from the original sale movement basis. |

Implementation after approval must replace the current `affectsCOGS()` conflation with an explicit counter-family classification (or an equivalently exhaustive mapping), and pin every enum case. `Damage`, `Expiry`, and `WriteOff` must not become no-ops merely because they are removed from `affectsCOGS()`. The specialized batch-write-off readiness check and writer must resolve `InventoryShrinkageExpense`, not `CostOfGoodsSold`.

## Country-defaults template and frozen fallback

The three `*ChartOfAccountsSeeder` classes remain untouched. `FrozenSeederDocblockTest` pins their exact content fingerprints, and the existing `coa.{tn,fr,generic}.legacy-v1` bootstrap rows remain immutable compatibility artifacts.

Under the owner approval, T20 now:

1. adds a distinct v2 country-defaults chart-template payload for TN, FR, and Generic, derived from the current v1 rows plus the approved shrinkage/gain rows;
2. imports each as a new immutable draft version (`coa.tn.default-v2`, `coa.fr.default-v2`, `coa.generic.default-v2`), leaving publish, certification, and assignment changes inside the existing Country Defaults lifecycle;
3. adds the approved purpose-first/code-second tenant backfill with fail-closed schema guards, savepoint isolation, stable warning-level summary token, and run-twice idempotency evidence; and
4. adds T20b parity plus the required baseline/mutation/restore proof.

**Frozen legacy fallback treatment:** purposes are deliberately absent from direct legacy-seeder output. No fingerprint re-pin is proposed. Companies provisioned through the frozen fallback therefore retain the existing guarded fail-soft behavior: an unmapped shrinkage/gain purpose logs and posts no variance entry. Existing legacy-derived companies receive the purposes only through the explicit backfill; a newly provisioned fallback company must receive that command before count/destructive-loss GL is expected. Tests must pin both the warning/no-entry fallback and the v2-template posting-ready path. This is a transitional compatibility posture, not evidence that the variance was booked.

## Source facts that make the gate binding

- `SystemAccountPurpose::expectedAccountType()` currently maps `InventoryGainIncome` to `Revenue` and `InventoryShrinkageExpense` to `Expense` (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:191`).
- Template publication rejects a purpose/type mismatch (`TemplatePublishingService::validateAccountRows`, `apps/api/app/Modules/CountryDefaults/Application/Services/TemplatePublishingService.php:255`).
- The three seeder fingerprints are pinned by `apps/api/tests/Unit/CountryDefaults/FrozenSeederDocblockTest.php`.
- Current posting sends every `affectsCOGS()` movement to `CostOfGoodsSold`, while `MovementReason::affectsCOGS()` includes `Damage`, `Expiry`, and `WriteOff` (`InventoryGlPostingService::postMovement`; `MovementReason::affectsCOGS`).
- `S-16` remains open at `docs/handoff/LEDGER.md`; no local zero-row probe discharges it.

## Owner disposition

`APPROVE OPTION A` is on file. Option B is rejected for this wave. The expert-comptable rider remains
open as a pre-live M5 gate; it does not reopen the M4 map decision.
