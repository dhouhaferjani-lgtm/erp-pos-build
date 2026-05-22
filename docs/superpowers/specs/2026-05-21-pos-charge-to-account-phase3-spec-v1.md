# POS Charge-To-Account Phase 3 Spec v1

**Date:** 2026-05-21  
**Branch:** `feat/fiscal-phase-3-charge-to-account`  
**Scope:** Phase 3 of the POS fiscal event engine roadmap: offline-first B2C charge-to-account, `ACCOUNT_CHARGE` fiscal events, customer credit rules, AR GL posting through Treasury, and B2B Facture routing through a bounded projector bridge.

## 0. Status And Gates

Phase 1, Phase 2, Phase 1.5.2, and Phase 1.5.3 are merged to `dev`. Phase 3 starts from `origin/dev` after PR #128. The fiscal event engine, `SALE_RECEIPT`, `ACCOUNT_PAYMENT`, POS customer mirror, account-payment printable, Treasury account-payment bridge, strict per-country tax-number validation, and parse-failure assist UX are already shipped.

Authoritative inputs:

- Roadmap v2 — `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`.
- Source-of-truth v3 — `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md`.
- Codebase reality audit — `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md`.
- Phase 1 handoff §4 — `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md`.
- Sale receipt synthesis v5 — `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md`.
- Phase 1 spec v7 — `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`.
- Phase 2 account-payment spec — `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md`.
- External multi-country research — `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md`.
- Project rules — `/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md`.

## 1. Locked Architecture

`ACCOUNT_CHARGE` is a device-authored fiscal event. It is **not** part of the Phase 1 §11.0 server-authoring carve-out, which is limited to company-integrity facts such as `TERMINAL_REGISTRY_SNAPSHOT` and `COMPANY_DAY_CLOSURE_MANIFEST`. The Tauri POS authors the event locally, canonicalizes once, seals it in the same fiscal chain as `SALE_RECEIPT` and `ACCOUNT_PAYMENT`, and syncs the sealed envelope to `/api/v1/pos/sync/fiscal-events`. The server verifies the device bytes and never re-serializes or replaces them. `[SoT §1, §11, D1-D6; Phase 1 spec v7 §11.0]`

There is no dual chain. `ACCOUNT_CHARGE` starts at `event_version: 1`; it is a new event type, not a new version of `SALE_RECEIPT`. Corrections are later compensating events, not mutations of the sealed charge event. `[SoT D4, D6; owner D2/D4]`

D16 remains load-bearing. POS-core must work without Treasury, Accounting, or B2B sales active. The fiscal engine, outbox ingestor, strict parser, canonical reader, and POS-core printable projector must not import or call Treasury, Accounting, B2B, Customer, or Partner operational services. Business effects outside POS-core are projector bridges resolved per `(tenant_id, company_id)` by `ModuleActivationResolver`. `[SoT §13.6, D16; Phase 1 spec v7 §7.3; Phase 2 spec §1]`

Reconciliation classification:

- `ACCOUNT_CHARGE` authoring, chain sealing, local credit-rule decision, and printable output are `offline_authoritative`.
- POS customer mirror freshness is a sealed snapshot fact at the time of charge.
- AR GL posting, Treasury balance refresh, server-side credit-limit conflict handling, and B2B Facture draft creation are `server_reconciles`.
- If a deployment policy demands online approval for a later high-risk override, that is Phase 4 approval-primitive work; Phase 3 remains offline-first by default. `[SoT §9.x, §13.6; Roadmap v2 §Phase 3]`

## 2. Codebase Facts

Existing Phase 1 and Phase 2 surfaces:

- `FiscalEventType` and the TS registry already reserve `ACCOUNT_CHARGE`; `FiscalEventEngine.append()` can author implemented device event types once the registry, payload DTO, parser, and validators are extended. `[Phase 1 spec v7 §17.1; apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php; apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts]`
- The POS has a local customer mirror and customer attach flow from Phase 2. It mirrors server `Partner` rows for offline search and account-payment balance snapshots. `[Phase 2 spec §4-§5; apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php; apps/pos/src/lib/customer/]`
- `Partner` already has `receivable_balance`, `credit_balance`, `credit_limit`, `payment_terms_days`, `balance_updated_at`, `is_active`, and `customer_category`. `CustomerCategory` is `individual` or `business`; `Partner::isB2B()` checks `CustomerCategory::Business`. `[codebase reality audit §5.1; apps/api/app/Modules/Partner/Domain/Partner.php]`
- Offline POS cannot create Treasury `Payment` rows locally. For Phase 3 it also cannot create AR journal entries locally. It authors a sealed event; server-side bridges project operational effects after ingest. `[codebase reality audit §3.1; SoT §13.6]`
- `ReceiptPaymentService` and `GeneralLedgerService::createPOSPaymentEntry()` are cash/bank-to-revenue paths only. `createPOSPaymentEntry()` debits the repository account, credits product revenue, has no AR line, no VAT line, and no `partner_id`. Routing an `on_account` marker through that path would create a fake payment and the wrong GL. `[codebase reality audit §2.1-§2.2]`
- AR posting exists in the B2B invoice path, not in the POS payment path. `PartnerBalanceService` derives receivable/advance balances from journal lines and refreshes Partner cache columns. `[codebase reality audit §2.2, §5.2]`
- Phase 2 refactored `PaymentAllocationService` to accept command DTO replay with explicit actor/context for `ACCOUNT_PAYMENT`; Phase 3 can reuse the explicit-command posture where it settles or refreshes account state, but it must not push AR creation through the account-payment bridge. `[Phase 2 spec §9; apps/api/app/Modules/Treasury/Application/DTOs/ApplyPaymentAllocationCommand.php]`

## 3. Phase 3 Deliverables

Phase 3 ships six bounded pieces:

1. `ACCOUNT_CHARGE` canonical contract and TS/PHP drift gates.
2. POS charge-to-account authoring flow: customer attach, credit-rule evaluation, payload assembly, engine append, and `ACCOUNT_CHARGE_RECEIPT` printable.
3. POS-core server projection for the printable/read model. It always runs and reads only the sealed payload.
4. Treasury charge bridge: optional projector that creates the AR GL posting path when Treasury is active and any accounting readiness is satisfied behind a Treasury-owned boundary.
5. B2B Facture bridge: optional projector that routes business-customer charges to a web B2B Facture draft, without POS authoring a Tax Invoice.
6. Full-flow closure tests for B2C, B2B, insufficient-credit, stale mirror, and POS-only deployments.

Out of scope:

- ZATCA Standard Tax Invoice authoring in POS.
- Browser web-POS device-authority parity.
- Account-status lifecycle, overrides, cash-out controls, manager approval PIN/push flows. Those are Phase 4.
- Deposits, AML identity reconciliation, and store credit. Those are Phase 5.
- Void/refund/return fiscal event migration.

## 4. Owner Decision D8: B2B Tax Invoice Boundary

**Question to owner:** For Phase 3 B2B charge-to-account, should the design lock that the Tauri POS authors only `ACCOUNT_CHARGE`, while the web B2B module consumes that event and generates a Facture draft or Facture aggregate, with no POS-authored Tax Invoice?

**Recommendation:** lock the **web-B2B-aggregates** model now.

Rationale:

- Owner D7 keeps Tauri POS B2C-only; owner D8 defers B2B / ZATCA Tax Invoice path.
- The roadmap says identified B2B charge-to-account routes through invoice flow, not POS ticket path.
- Source-of-truth D16 forbids the POS fiscal engine from depending on B2B operational runtime. A B2B bridge can consume the event when B2B is active; the engine and POS-core remain standalone.
- ZATCA Standard Invoice authoring is a separate Saudi-specific Phase 4+ workstream. Phase 3 must not quietly make POS a Standard Tax Invoice authoring surface.

Spec lock unless owner overrides: `ACCOUNT_CHARGE` can carry a business-customer snapshot and sale evidence, but the POS printable remains `ACCOUNT_CHARGE_RECEIPT`. When `customer.customer_category = business` and B2B sales is active, a B2B projector bridge creates or updates a Facture draft from the sealed payload. When B2B is inactive, the charge remains a valid POS fiscal event and printable; no Facture effect runs.

## 5. POS Customer Mirror Extension

Phase 3 reuses the Phase 2 customer mirror, then extends the mirrored data needed for offline credit decisions:

- `credit_limit` — bcformat string at company currency scale, nullable when no limit is configured.
- `payment_terms_days` — integer nullable; copied from `Partner`.
- `is_active` — boolean; inactive customers cannot be selected for a new charge.
- `customer_category` — existing mirror field. Treat exact `business` as B2B; treat `individual`, `retail`, `para-pharmacy`, null, and other non-business values as B2C/non-B2B for Phase 3. Do not destroy existing POS-local category values during sync.
- `receivable_balance`, `credit_balance`, `balance_updated_at` — existing Phase 2 balance snapshot fields.
- `charge_policy_version` — server-provided version string or integer for local rules, so the sealed event records which policy was applied.
- `charge_account_enabled` — boolean derived server-side from deployment/customer policy. Phase 3 may compute this from existing Partner fields initially, but the POS payload records the resulting fact.

Mirror writer rules:

- Upsert by `(tenant_id, company_id, id)` only.
- Reject any server row whose tenant/company differs from the active POS context.
- Never merge customer aliases across companies.
- Store the last sync cursor and mirror freshness timestamp; do not trust device clock for server freshness beyond comparing sealed server timestamps to configured thresholds.

## 6. Credit Rules Engine

The device runs a deterministic local rules engine before sealing:

Inputs:

- Customer mirror row.
- Cart totals and currency.
- Existing receivable and credit balances from the mirror.
- Credit limit and payment terms.
- Mirror staleness metadata.
- Deployment policy snapshot from local config.

Rules:

- Reject if customer missing, pending alias ambiguous, inactive, wrong tenant/company, or `charge_account_enabled=false`.
- Reject if charge currency differs from company/POS currency in Phase 3. Multi-currency account charges are deferred.
- Reject when `credit_limit` is present and `projected_net_balance_after_charge > credit_limit`.
- Reject when mirror freshness exceeds the configured hard-stale threshold and policy says charges require a fresh mirror. If policy is warn-only, allow seal but record the stale warning in the payload.
- Record every rule input and output in the sealed `credit_decision` block. Do not rely on live server state at print or projection time to explain why the device allowed or rejected the charge.

Rejected charges are not fiscal events. They are local UI outcomes only, unless Phase 4 later introduces override/audit event types.

Server reconciliation:

- The Treasury/B2B bridge may discover the server balance changed after the offline event was sealed. It must not reject or rewrite the fiscal event. It records a typed server reconciliation status and applies the configured operational policy: post and flag, dead-letter for operator review, or generate follow-up collection workflow.
- Server-side credit conflict is `server_reconciles`; it is not a chain-integrity failure.

## 7. ACCOUNT_CHARGE Payload Contract

`ACCOUNT_CHARGE` starts at `event_version: 1`. The payload follows the `SALE_RECEIPT` and `ACCOUNT_PAYMENT` precedent: a compliance-rich multi-country superset, deterministic canonical JSON, TS/PHP byte parity, no floats, money as bcformat strings, exact key-set validation, and strict parser rejection on extras. `[synthesis v5 §6, §7; Phase 1 spec v7 §11.2-§11.4; Phase 2 spec §6]`

Required top-level keys. The canonical encoder sorts object keys in bytes; this list is grouped by meaning:

1. `account_charge_uuid` — UUID of the local charge document.
2. `business_date`.
3. `buyer` — nullable SALE_RECEIPT-compatible fiscal buyer block.
4. `cashier_id`.
5. `cashier_name`.
6. `currency_code`.
7. `currency_scale`.
8. `customer` — sealed account/customer snapshot.
9. `event_time_device`.
10. `invoice_classification` — `b2c_charge_receipt` or `b2b_facture_draft_requested`.
11. `line_items` — sale evidence lines, same money/quantity scale rules as `SALE_RECEIPT`.
12. `local_balance_snapshot` — balance before and projected after charge.
13. `notes` — nullable.
14. `receipt_type_code` — fixed `ACCOUNT_CHARGE`.
15. `references` — nullable future/linkage block.
16. `regime_extensions` — nullable object for country-specific optional data.
17. `seller` — same seller block contract as `SALE_RECEIPT`.
18. `shift_id`.
19. `staleness` — customer/balance mirror freshness metadata.
20. `terminal_id`.
21. `totals` — sale totals and VAT totals.
22. `training_flag`.
23. `transaction_discount_amount`.
24. `transaction_discount_reason`.
25. `vat_breakdown`.
26. `credit_decision` — local rules engine evidence.
27. `charge_terms` — terms/due-date evidence.
28. `print_profile` — printable classification and labels.

`ACCOUNT_CHARGE` intentionally has **no `payments` block**. The customer is not paying now. Immediate settlement remains `SALE_RECEIPT`; money later received remains `ACCOUNT_PAYMENT`.

Nested `customer` block:

- `customer_id` — server Partner UUID or local pending customer UUID if server alias is still pending.
- `customer_sync_status` — `synced` or `pending_create`.
- `name`.
- `phone` — nullable.
- `email` — nullable.
- `tax_number` — nullable.
- `address` — nullable object using the `SALE_RECEIPT` address shape.
- `customer_category` — raw category snapshot. Exact `business` routes to the B2B bridge; every other value is non-B2B for Phase 3.
- `account_identifier` — nullable display-safe account/customer code.

Nested `buyer` block:

- Null when no fiscal buyer details are captured.
- When populated, it mirrors the `SALE_RECEIPT` buyer block exactly: `address`, `codice_fiscale`, `contact_id`, `customer_id`, `name`, and `tax_number`.
- `buyer.customer_id` may equal `customer.customer_id`; the duplicate is intentional. `customer` is the account/credit snapshot; `buyer` is the fiscal identity snapshot used by country adapters.
- Italy individual `codice_fiscale` remains first-class at `buyer.codice_fiscale`, not hidden inside `regime_extensions`, and uses the Phase 1.5.2 forensic prefix `payload_buyer_codice_fiscale_format_mismatch:field=buyer.codice_fiscale:value=<actual>`.

Nested `line_items` block:

- `line_uuid`.
- `product_id` — required stable product UUID from the POS product mirror.
- `sku` — nullable.
- `gtin` — nullable.
- `name`.
- `quantity` — bcformat string at quantity scale 3.
- `unit_price`.
- `line_subtotal`.
- `line_vat`.
- `vat_rate` — bcformat string at scale 2.
- `tax_category_code` — nullable string.
- `line_discount_amount`.
- `line_discount_reason` — nullable and required iff discount amount is greater than zero.
- `non_collected_subtype` — nullable enum matching the `SALE_RECEIPT` line contract, retained for Italy/future non-collected classifications.

Nested `totals` block:

- `subtotal`.
- `vat_total`.
- `total`.
- `grand_total_before_charge` — same value as `total` in Phase 3 unless later bundled flows add deposits/credits.
- `amount_charged_to_account` — must equal `total`.

Nested `local_balance_snapshot` block:

- `receivable_balance_before`.
- `credit_balance_before`.
- `net_balance_before`.
- `charge_amount`.
- `projected_receivable_balance_after`.
- `projected_credit_balance_after`.
- `projected_net_balance_after`.
- `balance_updated_at`.

Nested `credit_decision` block:

- `decision` — fixed `approved` for emitted events.
- `policy_version`.
- `credit_limit` — nullable bcformat string.
- `credit_available_before`.
- `credit_available_after`.
- `limit_exceeded` — boolean, must be false for emitted events unless `training_flag=true`.
- `mirror_stale_at_authoring` — boolean.
- `stale_policy_action` — `allow`, `warn`, or `block`.
- `warnings` — sorted array of stable warning codes.

Nested `charge_terms` block:

- `payment_terms_days` — nullable int.
- `due_date` — nullable date; required iff `payment_terms_days` is non-null.
- `terms_label` — nullable display string from local config.

Nested `staleness` block:

- `customer_snapshot_stale`.
- `balance_snapshot_stale`.
- `mirror_last_synced_at` — nullable timestamp.
- `staleness_reason` — nullable enum string.

Nested `references` block:

- `related_sale_receipt_event_id` — null in Phase 3; reserved to support later explicit split-sale links.
- `server_customer_alias_id` — null in v1 original event; alias reconciliation happens by projection state or future follow-up event, never by mutating the original.
- `external_reference` — nullable operator-entered reference.

`regime_extensions` is nullable in Phase 3. Country-specific adapters may later read it, but the base payload must already include the sale evidence, seller, buyer snapshot, totals, VAT, credit terms, and chain envelope needed for NF525/Tunisia immediate exports and future ZATCA/DE/IT projection work.

## 8. Payload Invariants

The validator enforces:

- Exact top-level key set and nested required keys.
- `receipt_type_code === 'ACCOUNT_CHARGE'`.
- No `payments` key anywhere in payload.
- `buyer` is null or exactly the SALE_RECEIPT buyer key set; `buyer.codice_fiscale` uses the landed Italy codice-fiscale validator.
- `line_items[]` includes the SALE_RECEIPT sale-evidence keys `product_id` and `non_collected_subtype`.
- Money fields match `moneyRegex(currency_scale)`, VAT rates match scale 2, quantities match scale 3.
- All money fields are non-negative.
- `totals.amount_charged_to_account === totals.total`.
- `local_balance_snapshot.charge_amount === totals.amount_charged_to_account`.
- `projected_receivable_balance_after = receivable_balance_before + charge_amount`.
- `projected_net_balance_after = max(projected_receivable_balance_after - projected_credit_balance_after, 0)`.
- `credit_decision.decision === 'approved'`.
- If `training_flag=false`, `credit_decision.limit_exceeded === false`.
- VAT partition rule mirrors synthesis v5 §6.C for `(vat_rate, tax_category_code)`.
- Discount reason invariant mirrors synthesis v5 §6.A.
- `invoice_classification === 'b2b_facture_draft_requested'` only when `customer.customer_category === 'business'`; otherwise it must be `b2c_charge_receipt`.

Forensic prefixes:

- `payload_account_charge_receipt_type_invalid`
- `payload_account_charge_payments_forbidden`
- `payload_account_charge_amount_mismatch`
- `payload_account_charge_balance_mismatch`
- `payload_account_charge_credit_decision_invalid`
- `payload_account_charge_invoice_classification_mismatch`
- Plus the shared money-scale, partition, discount, tax-number, and buyer-codice-fiscale prefixes from synthesis v5 and Phase 1.5.2.

## 9. Per-Regime Field Analysis

NF525 / France immediate:

- NF525 v2.1 material requires ticket identity, chaining, terminal, operator, payment method for immediate paid tickets, line discount detail, seller SIRET, audit trail, and fiscal archive support. `ACCOUNT_CHARGE` is not a money-received ticket, so it omits payment methods by design; it still carries terminal, cashier/operator, event time, business date, seller, line items, VAT breakdown, totals, discounts, customer snapshot, and a printable receipt type. `[multi-country research §1; synthesis v5 §7]`
- The AR nature of the event is explicit in `receipt_type_code`, `invoice_classification`, `totals.amount_charged_to_account`, `charge_terms`, and `credit_decision`. Export adapters must not fabricate a payment line from the charge.
- Seller tax-number validation uses the landed Phase 1.5.2 country-keyed validators: FR accepts SIREN/SIRET for seller/customer tax number and TVA intracommunautaire for buyer where applicable.

Tunisia immediate:

- Owner D9 prioritizes Tunisia; the NF525-style superset remains the baseline. The payload includes seller tax jurisdiction, seller tax number, terminal, cashier, date/time, line items, VAT totals, charge amount, customer snapshot, and account terms.
- TN matricule fiscal canonical producers emit compact form; slash-separated input is normalized before validation by Phase 1.5.2.
- Tunisian service e-invoicing timing and central-system integration remain volatile and are not implemented in Phase 3. If a Tunisian customer segment requires central integration, it is outside the current offline-first POS architecture until a later provider workstream. `[SoT §10, §15]`

ZATCA / Saudi deferred:

- Phase 3 does not implement ZATCA Standard Tax Invoice authoring. The B2B route is the web-B2B aggregate bridge, not POS Tax Invoice authoring.
- The rich payload carries seller/customer identity, line/VAT/totals, invoice classification, and event envelope anchors so a future Saudi adapter can reason about Standard vs Simplified requirements without redesigning the base event. Actual UBL C14N/XAdES signing and Standard Invoice clearance/reporting are Phase 4+.

Germany deferred:

- The payload carries DSFinV-K/EKaBS-relevant fields: terminal, cashier id/name, business date, event timestamp, seller, customer, line items, GTIN when known, VAT breakdown, totals, and training flag. It does not implement TSE signing in Phase 3. `[multi-country research §3]`
- A future TSE adapter may derive process data from the rich payload. Phase 3 must not add a fake signature status.

Italy deferred:

- The payload carries RT-relevant fields: terminal, business date, line items, VAT rates/categories, `non_collected_subtype`, totals, customer tax snapshots, buyer `codice_fiscale`, and `regime_extensions` for later lottery/RT-specific values. `[multi-country research §4; synthesis v5 §7]`
- Italy RT signing/transmission is not implemented in Phase 3.

## 10. Printable ACCOUNT_CHARGE_RECEIPT

The printable is POS-core and always available.

Required display fields:

- Title: `ACCOUNT CHARGE RECEIPT` or localized equivalent.
- Seller name, address, tax number.
- `account_charge_uuid` and fiscal event reference/QR where supported.
- Terminal ID, cashier name, shift ID.
- Event time and business date.
- Customer name, optional phone/account identifier, and business/individual classification when present.
- Line items, VAT breakdown, subtotal, VAT total, total.
- Amount charged to account.
- Previous balance, charge amount, projected balance.
- Credit limit and remaining available credit when configured.
- Due date / payment terms when configured.
- Staleness warning if customer or balance snapshot was stale.
- Training marker when `training_flag=true`.

The printable reads from sealed payload or POS-core projection only. It must not query live Partner/Treasury state to recompute balances or enrich customer data.

## 11. Device Authoring Flow

Device sequence:

1. Customer is selected from the local mirror or created as a pending local customer.
2. Cart totals are calculated using the existing sale assembler logic.
3. The credit rules engine evaluates the selected customer and cart total.
4. If approved, the POS builds `ACCOUNT_CHARGE` payload with no payment lines.
5. Device calls `FiscalEventEngine.append('ACCOUNT_CHARGE', payload)` inside one SQLite transaction.
6. The same transaction writes the local printable/projection row and any local cart/stock/voucher side effects that already belong to POS-core.
7. Existing fiscal-event sync flushes the sealed event to `/api/v1/pos/sync/fiscal-events`.

Fail-loud rules:

- Missing customer, wrong tenant/company mirror row, unresolved conflicting alias, inactive customer, invalid credit decision, or forbidden payment line blocks sealing with a typed device-side error.
- Stale mirror behavior follows policy: block, warn-and-seal, or allow-and-record. Silent downgrade is forbidden.
- The POS never calls server Partner/Treasury/B2B modules while sealing.

## 12. Server Projection

POS-core projection:

- Add `AccountChargeReceiptProjection` implementing `FiscalEventProjector`.
- `handlesEventType()` returns true for `ACCOUNT_CHARGE`.
- `requiresModule()` returns null.
- `priority()` returns 50.
- It creates an idempotent printable/read model keyed by `fiscal_event_id`.
- It reads only `CanonicalPayloadReader::forAccountCharge($event)` and event metadata.
- It must not import Treasury, Accounting, B2B, Partner, Customer, or Contact operational modules.
- Malformed payloads or projection invariant breaks throw typed projection exceptions; no silent skip.

Treasury charge bridge:

- Add `TreasuryAccountChargeBridge` implementing `FiscalEventProjector`.
- `handlesEventType()` returns true for `ACCOUNT_CHARGE`.
- `requiresModule()` returns canonical `'Treasury'`.
- `priority()` returns 150.
- It creates an idempotent AR GL posting by `fiscal_event_id` and tenant/company scope.
- It uses a new explicit-command method, recommended name `GeneralLedgerService::createPOSChargeEntry()` or a Treasury application service wrapping it. Do **not** branch `createPOSPaymentEntry()` for AR, because that method is explicitly cash/bank-to-revenue and lacks AR/customer semantics. `[codebase reality audit §2.2]`
- If Accounting has a separately inactive production state, the Treasury bridge must fail closed behind a Treasury-owned readiness check or shared contract. The Fiscal/POS engine must not gain a second hard dependency to test Accounting availability.
- The posting command includes tenant, company, partner/customer, fiscal event id, charge amount, VAT/totals, business date, actor/cashier, source context, and line/VAT summary.
- The journal entry shape is locked:
  - Debit `SystemAccountPurpose::CustomerReceivable` for `totals.amount_charged_to_account` / `totals.total`, with `partner_id` set to the resolved tenant/company-scoped customer.
  - Credit `SystemAccountPurpose::ProductRevenue` for net revenue, normally `totals.subtotal` under the locked AutoERP discount convention.
  - Credit `SystemAccountPurpose::VatCollected` for `totals.vat_total` when VAT is greater than zero. VAT partition detail comes from `vat_breakdown`; if a later accounting design requires one VAT-liability line per tax category/rate, that split is still derived from `vat_breakdown` and must balance to `totals.vat_total`.
  - Debit `SystemAccountPurpose::SalesDiscount` for `transaction_discount_amount` when the amount is greater than zero. This is the balancing line for the locked canonical invariant `subtotal + vat_total == total + transaction_discount_amount`; do not silently reduce revenue in one path and use a discount account in another.
  - Journal debits and credits must balance at `currency_scale`.
  - The entry source must include `fiscal_event_id` or an idempotent source identifier derived from it.
  - The entry must not create a Treasury `Payment` row, POS `ReceiptPayment` row, payment line, or call `createPOSPaymentEntry()`.
- Every FK lookup is tenant/company scoped. Missing customer, cross-company alias, missing AR account, missing revenue/VAT account, missing SalesDiscount account for a discounted charge, or conflicting existing posting throws a typed projection exception or dependency-missing exception according to retryability.
- If Treasury is inactive, no Treasury row or GL posting is attempted; POS-core still succeeds.

B2B Facture bridge:

- Add `B2BAccountChargeFactureBridge` or equivalent B2B-owned projector.
- `handlesEventType()` returns true for `ACCOUNT_CHARGE`.
- `requiresModule()` returns canonical B2B module token used by the existing module resolver; the plan must verify the exact token before implementation.
- `priority()` returns after POS-core and before or after Treasury based on concrete dependencies. The bridge must not assume Treasury projection succeeded unless it explicitly depends on a recorded projection row and fails loud when missing.
- It runs only when `customer.customer_category === 'business'` and `invoice_classification === 'b2b_facture_draft_requested'`.
- It creates or updates a Facture draft/aggregate from the sealed payload. It does not mutate the fiscal event and does not ask the POS engine to author a Tax Invoice.
- If B2B is inactive, no Facture draft is created; the POS-core charge receipt remains valid.

Shared-contract boundary:

- If the POS/Fiscal module needs to reference a bridge capability owned by Treasury or B2B, define an interface under `App\Shared\Contracts\<Module>\...` and bind it in the owning module provider. Do not import operational module services into POS-core or Fiscal code.
- Production code must use constructor injection only. No `app()`, `App::make()`, or Laravel `resolve()` calls.

## 13. Sync And Reconciliation Outcomes

Server sequence:

1. `OutboxIngestor` verifies hash and linkage, then persists the event verbatim.
2. `StrictCanonicalParser` validates `ACCOUNT_CHARGE` exact payload shape and constraints.
3. Projection registry creates rows for active projectors.
4. POS-core printable projection applies in every deployment.
5. Treasury bridge applies only when Treasury is active.
6. B2B bridge applies only when the B2B module is active and the sealed customer snapshot is business-classified.

Outcomes:

- POS-only: fiscal event verified, printable projection exists, Treasury and B2B bridges skipped.
- B2C with Treasury active: printable exists, AR GL posting exists, Partner balance refresh/reconciliation runs.
- B2B with B2B active: printable exists, Facture draft exists. Treasury posting also runs if Treasury active.
- Stale mirror conflict: fiscal event remains accepted; operational projection records `server_reconciles` status, retry/dead-letter, or operator-review reason.
- Conflicting duplicate projection: fail loud; never create duplicate GL entries or duplicate Facture drafts.

## 14. Tests And Gates

Round-1 test matrix must include:

- TS/PHP payload registry implements `ACCOUNT_CHARGE` at version 1 and keeps server-only set unchanged.
- TS/PHP canonical parity for golden `ACCOUNT_CHARGE` fixtures.
- Strict parser accepts positive `ACCOUNT_CHARGE` fixtures and rejects extra keys, missing keys, payments block, malformed money, malformed VAT partition, and invalid invoice classification.
- Constraint validator covers B2C vs B2B, synced vs pending customer, stale vs fresh mirror, credit-limit present vs absent, discount present vs absent, nullable vs populated `buyer` / `references`, Italy `buyer.codice_fiscale`, required `line_items[].product_id`, nullable `line_items[].non_collected_subtype`, and training vs production.
- Device rules engine rejects inactive customer, wrong-company customer, limit exceeded, hard-stale mirror, ambiguous alias, and any payment line.
- Device authoring appends through `FiscalEventEngine` and writes printable data in one SQLite transaction.
- POS-core projection reads canonical payload only and passes D16 grep guard.
- Treasury bridge creates AR GL posting idempotently with tenant/company scoped FKs, exact debit AR / credit revenue / credit VAT line assertions, partner attribution, no Treasury `Payment` row, no POS payment line, and fail-loud behavior on missing/cross-tenant customer or missing accounts.
- Treasury bridge discounted-charge test: with `transaction_discount_amount > 0`, assert the entry debits AR for `total`, debits `SalesDiscount` for the discount amount, credits `ProductRevenue` for `subtotal`, credits `VatCollected` for `vat_total`, balances at `currency_scale`, and still creates no payment rows or payment lines.
- B2B bridge creates Facture draft only for business customers and only when B2B active.
- POS-only deployment test: `ACCOUNT_CHARGE` ingests/projects printable and skips Treasury/B2B bridges.
- Full-flow closure: device authors `ACCOUNT_CHARGE` → seals → syncs → server ingests/parses/projects → printable reads canonical → AR GL and/or Facture effects apply when modules active → canonical byte equivalence holds.
- Chokepoint sentinels prove no server-side authoring path creates `ACCOUNT_CHARGE` or reuses legacy receipt/payment routes for charge-to-account.

Required gates per task:

- `./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
- `./vendor/bin/phpstan analyse --level=8 <touched PHP paths>`
- `./vendor/bin/pint --test <touched PHP files>` or full Pint when touched set is broad.
- `pnpm test`, `pnpm typecheck`, and `pnpm lint` in `apps/pos` for POS work.
- `pnpm test`, `pnpm typecheck`, and `pnpm lint` in `apps/web` for B2B/admin UI work.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh`.
- Any new `ACCOUNT_CHARGE` drift/sentinel gates introduced by the plan.

## 15. Standing Pattern Carry-Forward

Mandatory review axes for every Phase 3 task:

- D16 bounded-module guard: POS-core and Fiscal must not import Treasury, Accounting, B2B, Partner, Customer, or Contact operational services.
- Cross-tenant FK safety: every server lookup is scoped by tenant and company where columns exist.
- Fail-loud over silent downgrade: projector dependency failures throw typed exceptions or dead-letter observably.
- Dead-path rebuild: every new DTO, projector, route, command, service, migration, and UI path has a live caller and a test.
- Discriminated-union matrix completeness in round 1.
- Contract drift prevention: docs, PHP DTOs, TS payloads, validators, and golden bytes match.
- Per-method `markTestSkipped()` only; never class-level.
- Skip-citation accuracy: grep-verify cited surviving owner before leaving a skip.
- No production `app()`, `App::make()`, or Laravel `resolve()`.
- R2 fixes require fresh Codex self-review and Opus second-pass review.
- PG-specific fiscal test classes must be added to the CI PG merge-gate filter in the same commit.

## 16. Implementation Plan Inputs

The Phase 3 implementation plan should split work into these atomic tasks:

1. `ACCOUNT_CHARGE` payload contract, DTOs, TS mirror, parser constraints, and drift gates.
2. POS customer mirror credit fields and sync extension.
3. Device credit rules engine.
4. Device charge-to-account authoring flow and local printable.
5. Server canonical reader and POS-core `ACCOUNT_CHARGE_RECEIPT` projection.
6. AR GL posting command/service design and `GeneralLedgerService::createPOSChargeEntry()` or equivalent.
7. Treasury `ACCOUNT_CHARGE` bridge with idempotent AR posting and balance refresh/reconciliation.
8. B2B Facture bridge behind module activation, pending owner D8 lock.
9. POS-only, Treasury-active, and B2B-active integration regressions.
10. Full-flow closure, roadmap status update, handoff §4 refresh, and memory refresh.
