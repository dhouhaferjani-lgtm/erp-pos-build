# UoM Display Precision — Spec Rev 1 Adversarial Review Record (2026-07-20)

Three Opus lanes, all **APPROVE-WITH-FIXES** → reconciled into Spec Rev 2. Controller resolved one inter-lane contradiction (see below).

## Inventory-costing lane — A-W-F
- F1 MAJOR: `units.rounding_method` is a `RoundingMethod` enum (Unit.php:65); `QuantityScale::round` matches on strings and throws on the default arm — spec sketch must pass `->value`. Enum cases half_up/floor/ceil match QuantityScale constants (verified parity).
- F2 MAJOR: "updateOrCreate covers backfill" false for ParapharmacySeeder (`create()` only, fresh tenant per run, :1283-1290,:490); only DemoPharmacySeeder::seedTunisiaStock is re-run-safe (:675-687) and its values array lacks min/max. DemoPharmacySeeder re-randomizes qty per run (`shopQuantityFor` :704-713) → formula must be deterministic independent of qty.
- F3 MAJOR: seeded suggestion assertion flaky (qty=2 → suggestion 1 ≡ floor; min rounds to 0 at 0dp for qty<3; service is location-scoped). → pin fixed grain qty≥3 at named shop.
- F4 MAJOR: AddToPoDialog:140 / CreateTransferDialog:141 also hardcode `min="0.0001"` (unsatisfiable at 0dp); SupplierInvoiceCreatePage:626 carries max.
- F5 MINOR: R1 conflates requested_qty (queue renders it; resource :25; test pins :85,93,106) with suggested_qty; POS fixtures also pin strings.
- F6 MINOR: claimed device feed = SyncController::pull ($product->toArray(), no unit eager-load). **SUPERSEDED — controller verified `pos/sync/pull` has zero client callers; real feed is `/products`.**
- Confirmed: formatForUnit name free; variants carry no unit (product-grain OK); single ReplenishmentRequestResource feeds POS+web; getQuantityDecimals clamps [0,4]; v62 correct next.

## Fiscal-POS lane — A-W-F
- 1 MAJOR: `/products` ALREADY emits quantity_decimals (ProductData:42,93; ProductController:102 eager-load) — POS gap is CLIENT-ONLY; do not touch SyncController (divergent-source risk).
- 2 MINOR: refill sheet already holds full `POSProduct` prop (call sites ProductDetailDrawer:214, HomePage:1711) — no repo join; precision from prop.
- 3 MINOR: upsertProducts needs 4 coordinated edits (PARAMS_PER_ROW :138, value clause :149-151, column list :195, ON CONFLICT :197-216) — mismatch silently corrupts batch rows. 50×19=950<999 OK.
- 4 MINOR: POS test fixtures pin '6.0000' (RequestRefillSheet.test.tsx:130,143,164,206; replenishmentSyncService.test.ts:204).
- Confirmed: v62 idempotence pattern (v61 :1899-1911); regexes accept `1` and `1.0000` both sides; pull parsers whitelist (old devices safe); rule-20 clean; zero fiscal surface.

## Frontend-conventions lane — A-W-F
- 1 BLOCKER: TWO divergent web `formatQuantity` — decimal.ts:206 PADS (canonical), format.ts:133 TRIMS (opposite). No third helper; Guard 4 must anchor on canonical import.
- 2 MAJOR: no-literal-decimal-places would flag ~22 legitimate fixed-scale sites (percent/money/points: ProductPricingSection:165, EarningRuleFormModal:234+, expense forms, TierFormModal, ServiceForm, VariantEditor) — scope to product-quantity feature dirs.
- 3 MAJOR: no-raw-quantity-input false-positives: MoneyInput.tsx:48 atom, ShiftClosurePage:99 (cash), CustomerAttachPanel:280; sole true positive RequestRefillSheet:167. → source allowlist.
- 4 MAJOR: `_quantity` suffix heuristic dominated by non-actionable matches (~28 web + ~54 pos; aggregates/thresholds: total/available/reserved/stock/min/max/component/required) → explicit inclusion list + exclusion set.
- 5 MAJOR: ReplenishmentCapturePage:77 quantity field renders pre-product-selection — getQuantityDecimals(product) inapplicable → EXCLUDE + exempt (ticket optional UX restructure).
- 6 MINOR: R4 enumeration — :538 already has field via ProductPickerValue (ProductPicker:21-27,73-74); :626 needs receipt-lines resource + types.ts:213-234 + prefilledLines :240-283; queue/dialogs all served by ReplenishmentLine.
- 7 MINOR: POS atom must use POS semantic tokens, not web designTokens import (rule 18).
- 8 MINOR: no-hardcoded-step does NOT exist in apps/pos/eslint-rules (copy needed); ESLint guards land warn+ratchet — Goal 3 delivered via ratchet, state explicitly. Also ReplenishmentActionsTest.php:97,145 pin '1.0000'.
- Confirmed: audit-tanstack-keys wiring template accurate (package.json lint, preflight :178, ci.yml); POS structural gap real; QuantityInput contract fits; no new i18n keys needed.

## Controller resolution
- POS-lane #1 vs inventory F6: grepped all POS client code — zero calls to `pos/sync/pull`; `pullProductsCore` hits `/products`. POS lane correct; F6 recorded as superseded; SyncController retirement = separate ticket.

All findings folded into Spec Rev 2 §§1, 3.1-3.6, 5, 6.
