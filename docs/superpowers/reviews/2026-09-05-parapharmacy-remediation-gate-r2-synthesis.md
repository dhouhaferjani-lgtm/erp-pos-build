# Gate r2 synthesis — parapharmacy remediation spec v2

Date: 2026-09-05. Orchestrator: Claude Fable 5.1. Input: spec v2 (working tree, Codex fix round 1) at HEAD `f75aa5023` (source identical to `b9a5565aa`). Four independent Opus reviewer lenses, each verified against code, read-only:

| Lens | File | Verdict |
|---|---|---|
| Fiscal / POS | [gate-r2-fiscal](2026-09-05-parapharmacy-remediation-gate-r2-fiscal.md) | CHANGES-REQUIRED (1 blocker, 4 major, 5 minor) |
| Inventory / lot | [gate-r2-inventory](2026-09-05-parapharmacy-remediation-gate-r2-inventory.md) | CHANGES-REQUIRED (1 blocker, 7 major) |
| Treasury / GL | [gate-r2-treasury](2026-09-05-parapharmacy-remediation-gate-r2-treasury.md) | CHANGES-REQUIRED (2 blockers, 5 major) |
| Tenancy / authz / module gating | [gate-r2-authz](2026-09-05-parapharmacy-remediation-gate-r2-authz.md) | CHANGES-REQUIRED (2 blockers, 6 major) |

**Gate r2 verdict: CHANGES-REQUIRED.** v2 is a real improvement over v1: all four lenses confirm every source citation resolves at HEAD, F-B, F-C, F-D and the narrowing half of F-E are genuinely closed, all ten decision rows are OPEN in §2.3, and R1–R4 are represented. The blockers are new facts the fix round surfaced or inherited, not rewording failures.

## Correction to gate r1 (owned by the orchestrator)

r1 F-A stated "Vouchers and customer-account legs never reach the tender resolver." **That was wrong.** `PosCoreReceiptProjection.php:287-288` says voucher legs ARE payment legs, and `TreasuryReceiptBridge.php:480` loops every `payments[]` leg into the resolver with no filter. v2 promoted the error into a requirement (spec line 139), which is treasury BLOCKER-1. The r1 file stays immutable; this synthesis is the correction of record.

## Blockers (spec approval), deduplicated across lenses

| # | Blocker | Lenses | Minimum correction |
|---|---|---|---|
| B1 | **Silent owner decision: sealed `repository_id` on a new SALE_RECEIPT version** is written as the recommended W2 transport (spec:143) with no decision row, while the structurally identical lot-field question is OPEN as D7. | fiscal M-1, treasury MAJOR-2, authz M-4 | Add **D8 (OPEN)**: sealed tender binding in SALE_RECEIPT vN+1 vs unsealed policy snapshot + server binding. State the repair path when a sealed binding is unresolvable at projection (today `TreasuryReceiptBridge:1297-1299` intends current-mapping resolution). Coordinate D7/D8 as one version roadmap (fiscal MINOR: two cutovers). |
| B2 | **Voucher and account legs DO reach the tender resolver.** The 3-value classification (cash / maturity / settlement) classifies seeded `MEAL_VOUCHER` and `LOYALTY` as `settlement` by elimination, booking liability extinguishment into a bank clearing repository; RD3's reversal would also make them unavailable on day one. | treasury BLOCKER-1 | Restore a non-money-destination class for voucher/loyalty/account legs (r1's shrink was wrong). Census every leg type the bridge routes (`PaymentMethodSeeder`), name the destination rule per class, and keep RD3 scoped to electronic settlement only. |
| B3 | **W1 scoping breaks the POS device.** `/payment-repositories` is the exact endpoint the device syncs (`syncService.ts:1256`, cached `paymentStore.ts:950-962`); a location-restricted cashier would lose every bank/virtual row and card checkout hard-throws at `paymentStore.ts:1188`. No device consumer in W1 seams or acceptance. | treasury BLOCKER-2 | W1 must census device consumers and define the device projection (narrow fields, location-appropriate rows, or a dedicated sync endpoint) before scoping the human endpoint. Ties to B1/W2: once the binding is server-authored the device needs less of this list. |
| B4 | **DEFAULT lot has no identification/split operation.** Opening import mints `batch_number='DEFAULT'` for every product (`ProductOpeningStockPhase.php:71-74`, `BatchStockService.php:31,104`); L2 freezes the only rename surface after first movement; W5 requires a "recorded identification decision" that has no surface. Day-one counts cannot finalize honestly. | inventory BL-1 | Add gap **L9 — DEFAULT/unknown lot identification and split** to W-LOT: a permissioned operation that splits a DEFAULT cohort into identified lots with quantities, evidence and history, idempotent, no aggregate/GL change. Sequence L9 before L4. |
| B5 | **R2 is unenforceable as written and violated today.** BatchExpiry is a parapharmacy `default_module` (`config/verticals.php:355`), extras are additive-only with no disable path (`CompanyConfigService.php:71`), and `PosCoreReceiptProjection::requiresModule()` returns null while the lot arm gates on the product flag only (`FEFOInventoryService:930-934`). A retail tenant can set `requires_batch_tracking` (`CreateProductRequest:226`) and accrue unentitled lot legs. `LotLedgerDriftCensus.php:81` has no module filter either. | authz BL-1, fiscal M-5, inventory MJ-5 | Restate R2 as a **fix**, not a census: projection/worker lot arms resolve tenant/company module entitlement explicitly (company-level; today `DefaultModuleActivationResolver:56` drops `companyId` and caches 24h — authz M-3), product flag alone is insufficient, and the drift census filters by entitlement. Add an owner row **D9 (OPEN)**: is BatchExpiry deactivatable for parapharmacy, or always-on for that vertical with R2 meaningful only for other verticals? |
| B6 | **L1 gates only the backend.** `batches.*` permissions are seeded (`RolesAndPermissionsSeeder:403-409`) but only manager and cashier hold any; `Sidebar.tsx:222` and `features/batches` gate on module only. New `can:` guards produce visible-but-403 surfaces (rule 12: both layers). | authz BL-2 | L1 names the web layer (`RequirePermission` / `usePermissions` on batch routes and actions) and a role-delta table (which seeded roles gain which `batches.*`), with RD2 governing manager recall. |

## Majors (block the execution plan, fold into v3)

- **W4 second recovery surface** (fiscal M-3): `EnqueueResolvedEventProjectionsCommand.php:245,358,439-440` already creates missing rows and re-dispatches pending rows at any attempt count, and re-seeds from today's module activation. W4 must extend or retire it, not add a parallel dispatcher.
- **W6 undeclared second surface** (fiscal M-4): `NearExpirySlot.tsx` is a shipped reserved plug-point already mounted in ProductCard/ProductListRow/ProductTable. The iteration-1 suggestion must land there (convention 11).
- **ACCOUNT_PAYMENT precedent is server-only** (fiscal M-2): device seals `repository_id` as optional string (`FiscalEventEngine.ts:2844`), bridge hard-throws on null (`TreasuryAccountPaymentBridge:406-409`). Copying it moves the failure. D8 must include device-side validation.
- **W3 PG acceptance too narrow** (treasury MAJOR-3): `AccountingService:591-609` takes no company advisory lock; POS treasury and adjustment writers are the real collision surface, not invoice-vs-invoice.
- **Second-company repository code uniqueness** (treasury MAJOR-4): `PaymentRepositoryController:80` vs migration `2025_12_30_195300:31-35` tenant-wide `code` unique blocks the second-of-everything fixture W2 requires.
- **Classification is a 4th surface** (treasury MAJOR-6): beside `is_cash_tender` / `has_maturity` / `instrument_kind`; register in glossary or derive from existing flags.
- **L5 provenance scope** (inventory MJ-1): `document_sales` branch of `BatchTraceabilityController:60-74` and `stock_transfer_line_batch_allocations` also present FEFO estimates; glossary declares one of three surfaces.
- **L3 float census incomplete** (inventory MJ-2, fiscal MINOR): `BatchStock::getAvailableQuantityAttribute(): float`, `reserve/releaseReservation/adjustQuantity(float)`, `Batch.php:143-151` → `BatchResource:40,59`, `FEFOInventoryService::getTotalAvailableQuantity(): float`.
- **W5 reattribution GL shape unstated** (inventory MJ-3): `InventoryGlPostingService:37-52` posts on any non-flat row; a +5/−5 lot reattribution must be a `flat` / no-GL movement pair.
- **W5 lock census missing** (inventory MJ-4): canonical order `WeightedAverageCostService:87-95`; `FOR UPDATE SKIP LOCKED` in `FEFOInventoryService:264-272` means a count finalize silently diverts concurrent sealed sales.
- **R3 cache data contract** (inventory MJ-6/7): spec cites `types/product.ts` (`stock_quantity: number`) instead of the decimal-string surfaces (`stockDistribution.ts`, `gridStock.ts:31-38`); suggestion recompute must net reservations and the device's own cart/sales as `stockGate` already does.
- **G1 ratchet rule** (authz M-1): `permission:` middleware does not exist; the real one is `require.any.permission` (`bootstrap/app.php:113-121`); enumerate via the router, not file glob; add a public-route class. Measured baseline: 559 mutating routes, 205 without inline `can:`, ~77 with no visible check.
- **W1 reauthorize-in-orchestration** (authz M-2): `LocationScopeResolver` is HTTP-only; the adjustment service has a queued caller (`PostShiftCashVarianceAdjustment:199,267`).
- **`treasury.manage_all_locations`** (authz M-5): no seeding/grant plan; "owner/admin" names a non-existent role (roles: admin/manager/cashier/viewer/technician/operator/accountant).
- **Location-scope baseline** (authz M-6): 15 files use the resolver incl. two other Treasury controllers; `BatchController::expiring()` unscoped while `expired()` scopes.

## Preserve (verified correct, do not re-litigate)

Redelivery idempotency via `insertReceiptOnConflictDoNothing` (:461-466); `fiscal-projections` queue covered at `config/horizon.php:209`; cheques, change netting, cash rounding, per-leg idempotency (`TreasuryReceiptBridge:1297-1310`), frozen replay (`TreasuryMovementService:91-96`); W4's precise seeding statement; W7 RD4 gating in three places; W1 narrowing + adjustments route + RD2 open; DEFAULT-pin demotion; refusal to promote a suggestion into `operator_captured`; `product_batches` uniques already carry `company_id`; BatchExpiry route group is rule-12 compliant.

## Owner decisions after r2

D1–D7, RD2–RD4 remain OPEN. New rows required: **D8** sealed tender binding transport; **D9** BatchExpiry deactivatable for parapharmacy or vertical-always-on. Owner note on R2: for the launch vertical the module is a default; R2's value is retail/other verticals and the worker-side entitlement fix.

## Next

Codex fix round 2 → spec v3 (handover `docs/handoff/HANDOVER-parapharmacy-remediation-codex-spec-fix-round-2-2026-09-05.md`) → Fable gate r3 (same four lenses, re-verify only B1–B6 + majors). No implementation before ACCEPT-FOR-OWNER-REVIEW and owner rulings.

VERDICT: CHANGES-REQUIRED
