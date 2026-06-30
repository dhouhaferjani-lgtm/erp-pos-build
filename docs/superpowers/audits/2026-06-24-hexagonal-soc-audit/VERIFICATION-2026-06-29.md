# Verification Addendum — 2026-06-29

**Purpose:** Re-confirm whether the 2026-06-24 audit findings are still valid after **22 merges** landed on `dev` in the interim.
**Method:** 8 read-only verification agents (one per area file) re-checked every cited `file:line` and re-derived every metric against `dev` tip `5aee57488` (== `origin/dev`).
**Verdict:** The audit is **~95% still accurate.** Almost nothing was fixed; several structural metrics drifted *worse*; frontend volume-debt improved materially; the Media subsystem began the exact refactor the audit recommended. Two calibration corrections below.

---

## 1. All six P0 (money-correctness) findings remain LIVE

| P0 | Status | Current evidence |
|---|---|---|
| P0-1 WAC float-typed end-to-end | **STILL PRESENT** | `Inventory/Application/Services/GoodsReceiptService.php:158,169` `(float)` casts; `WeightedAverageCostService` `recordPurchase(float,float)` / `calculateNewWAC(...): float` signatures intact. PR #147 fixed **concurrency only** — the float boundary is untouched. |
| P0-2 Document line total via float | **STILL PRESENT** | `Document/Domain/Services/DraftPersistenceService.php:248,386,578` — `(string)($quantity * $unitPrice)`. |
| P0-3 Billing float arithmetic + float status gates | **STILL PRESENT** | `Billing/Domain/Invoice.php:195-227`, `Payment.php:188-235`, `InvoiceItem.php:96-112`, `Refund.php:108`; `AdminBillingController.php:277,341,388`. Paid/fully-refunded still decided by float comparison. |
| P0-4 POS discount/change via float | **STILL PRESENT** | `POS/Application/Services/ReceiptCreationService.php:1004-1015` → `Domain/Services/DiscountCalculationService.php:144`; `ReceiptPdfService.php:133` change-given via `(float)`. |
| P0-5 Frontend POS payment float sum | **STILL PRESENT** | `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:217` — `reduce((s,p)=>s+p.amount,0)` while line ~197 uses `bcadd`. Survived the POS caisse redesign. |
| P0-6 Tax/fiscal output floated | **STILL PRESENT** (1 partial fix) | `Taxation/Application/Services/CertificatePDFService.php:85-88`; `Taxation/Infrastructure/Exporters/MtdJsonExporter.php:68,89-97`; `WithholdingCalculation.php:116`. **Fixed:** `WithholdingTaxRule::getRateAsPercentage()` now returns a `string` (no float cast). |

---

## 2. Cross-cutting metrics drifted WORSE, not better

| Metric | Audit (06-24) | Now (06-29) | Δ |
|---|---|---|---|
| **Deptrac violations** | 59 (claimed "0 drift since 2026-05-14") | **70** | **+11** |
| Cross-module Domain/Eloquent imports | 726 | 775 | +49 |
| `DB::` inside `*/Domain/*` | 84 | 93 | +9 |
| `DB::` raw SQL in Domain | 10 | 10 | 0 |
| `(float)` casts (non-test backend) | 185 | ~180 | flat (grep noise) |
| `number_format(` (backend) | 24 | 25 | +1 |
| `app()` / `resolve()` service-location | 22 / 34 | 23 / 36 | +1 / +2 |

> **README §3 correction:** the "Deptrac green and honest, 0 drift" framing **no longer holds** — it is at **70 (+11)**, +9 of which is in SharedContracts (module application DTOs leaking into shared contracts). The green-badge reassurance in §1 should be read as stale.

---

## 3. What genuinely improved (all frontend / Media)

- **Frontend volume debt (P3):** design-token files ~154 → 323 (~2×); `parseFloat`/`Number` on money ~40 → 12; `: any` in src 31 → 15; raw hardcoded color classes down substantially. Real progress — but mechanical, low-correctness-risk debt, not the P0s.
- **Media (P2-3):** now has a real Infrastructure layer (`Media/Infrastructure/Storage/MediaStorageAdapter` implementing `MediaStorageInterface`); media-unification refactor is **in-flight (June 26–28 merges)** — exactly the `MediaAsset` path the audit prescribed. Continue to leave the legacy `DocumentAttachment` path alone.

Unchanged-or-worse god components: `apps/web/src/routes/index.tsx` grew 2,699 → 2,825 lines; `ReceiptCreationService` (1412), `ReceiptReturnService` (1326), `Nf525DataProvider` (1694), `AuthController` (932), `UserController` (776), `MonitoringService` (763) all unchanged.

---

## 4. Calibration corrections to the original report

1. **P2-5 Workshop "nested hexagons"** — verified that `Workshop/{Bundle,Technician,WorkOrder}/` each carry a **full 4-layer structure** (Application/Domain/Infrastructure/Presentation). The "silently dodges deptrac globs / decide promote-vs-restructure" framing is overstated; they are structurally compliant. **Downgrade** (residual nit: `TechnicianServiceProvider.php` at sub-context root rather than `Providers/`).
2. **Deptrac "0 drift since 2026-05-14"** — factually wrong as of 06-29 (59 → 70). See §2.

---

## 5. Net guidance

The 06-24 audit is **safe to act on as-is** for all P0/P1 priorities — every one is confirmed live in a path that runs. The only stale parts are *understatements*: structural debt is worse than reported and the deptrac reassurance is outdated. Recommended next step is unchanged from README §5: **P0 precision sweep first**, **P1-1 cross-module deptrac ruleset in parallel** (now more urgent given the 726 → 775 creep and the +11 deptrac drift with no enforcement).

---

## 6. P0 sweep — fixed 2026-06-30

All 6 P0 precision violations have been fixed via TDD on branch `fix/precision-p0-sweep` (worktree `erp.hex-p0`).

| P0 | Description | Status | Commit(s) |
|---|---|---|---|
| P0-1 | WAC pipeline float rebase (`WeightedAverageCostService`) | **FIXED** | `46ac02a4a` |
| P0-2 | `DraftPersistenceService` `line_total` float product | **FIXED** | `7f1cc7f80` |
| P0-3 | Billing entity money via float (Invoice/Payment/InvoiceItem/Refund + MRR) | **FIXED** | `2c7b05f6f` + `e044b261a` + `c3b739e72` |
| P0-4 | POS discount percent + change-given float (`DiscountCalculationService`, `ReceiptCreationService`, `ReceiptPdfService`) | **FIXED** | `49e83650d` + `64c0a04bf` |
| P0-5 | Frontend POS payment float sum (`AdvancedPaymentsModal`) | **FIXED** | `ff5c4263f` + `c76fd8ed1` |
| P0-6 | Tax/fiscal output floated (`CertificatePDFService`, `MtdJsonExporter`) | **FIXED** | `d12f1f28f` + `83ba255dd` |

### Guard coverage (Step 2 outcome)

Both PHPStan rules run **repo-wide** (no path allowlist):
- `ForbidFloatCastOnDecimalProperty` — matches any `(float) $model->prop` where the property carries a `decimal:*` Eloquent cast; enforced across the entire `app/` tree.
- `ForbidHardcodedBcmathScale` — matches literal integer scale args to bcmath functions; restricted by code to `Application/Services/` and `Domain/Services/` directories under any module. All cleaned service files fall within this scope.

No config changes were required. The baseline (`phpstan-baseline.neon`) was trimmed to remove 18 stale entries that the P0 sweep eliminated:
- **Removed entirely** (all violations fixed): `Billing/Domain/Invoice.php` (2), `Billing/Domain/InvoiceItem.php` (4), `Billing/Domain/Payment.php` (2), `Billing/Domain/Refund.php` (1), `Billing/Presentation/Controllers/AdminBillingController.php` (1), `POS/Application/Services/ReceiptPdfService.php` (1), `Taxation/Application/Services/CertificatePDFService.php` (3).
- **Float cast entries removed, bcmath scale entries kept** (some violations remain, now properly suppressed): `POS/Domain/Services/DiscountCalculationService.php` (2 float cast entries removed).
- **Counts reduced** (partial fix): `Inventory/Application/Services/WeightedAverageCostService.php` — `bcadd` count 3→1, `bccomp` count 4→3.
- **No changes** (no stale entries): `Document/Domain/Services/DraftPersistenceService.php`, `Taxation/Infrastructure/Exporters/MtdJsonExporter.php`, `Inventory/Application/Services/GoodsReceiptService.php`, `POS/Application/Services/ReceiptCreationService.php`.

### Slice preflight (2026-06-30)

- **Pint:** all 13 production PHP files — clean (no changes)
- **PHPStan:** all 13 production PHP files — 0 errors with updated baseline
- **PHPUnit:** 162 tests, 356 assertions — all green (7 skipped env-dependent, 14 pre-existing deprecations)
  - `DraftPersistenceServiceTest`, `DiscountCalculationServiceTest`, `TransactionDiscountValidationTest`, `ReceiptPdfChangeDueTest`, `DiscountEnforcementTest`, `WeightedAverageCostServiceTest`, `GoodsReceiptTest`, `WithholdingPrecisionTest`, `WithholdingCertificateTest`, `MoneyBcmathTest`, `CreateManualInvoicePrecisionTest`
- **Frontend typecheck:** `pnpm typecheck` — 0 errors
- **Vitest:** `AdvancedPaymentsModal` — 28/28 tests pass (2 test files)
