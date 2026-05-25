# POS Customer Accounts Phase 2 Spec v1

**Date:** 2026-05-21  
**Branch:** `feat/pos-customer-accounts-phase2`  
**Scope:** Phase 2 of the POS fiscal event engine roadmap: On-Account Payment + Customer Attach for offline Tauri POS deployments serving para-pharmacy and similar B2C verticals.

## 0. Status And Gates

Phase 1 is merged to `dev` and Phase 1.5 task 1 is complete (`Phase 1.5.1: Record mirror-column audit`). The audit retained the mirror columns because live consumers still exist; no schema deletion was safe.

Phase 1.5 task 2 remains a **blocking launch gate**: strict per-country tax-number validation cannot be implemented until the owner/accountant confirms the immediate TN matricule fiscal and FR SIRET accepted formats. This spec may be locked before that confirmation, but no customer-facing Phase 2 deployment ships until the Phase 1.5 gate is cleared.

Authoritative inputs:

- Roadmap v2 — `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`.
- Source-of-truth v3 — `docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md`.
- Codebase reality audit — `docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md`.
- Phase 1 handoff §4 — `docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md`.
- Sale receipt synthesis v5 — `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md`.
- Phase 1 spec v7 — `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`.
- External multi-country research — `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md`.
- Project rules — `/Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md`.

## 1. Locked Architecture

Phase 2 reuses the Phase 1 fiscal event engine exactly as the source of truth defines it: the device authors and seals locally; the server verifies the exact canonical bytes and mirrors them without re-serializing; business effects are retryable projections after ledger ingest. `[SoT §1, §13; Phase 1 spec v7 §5.0]`

`ACCOUNT_PAYMENT` is a device-authored fiscal event. It is **not** part of the Phase 1 §11.0 server-authoring carve-out, which is limited to company-integrity event types such as `TERMINAL_REGISTRY_SNAPSHOT` and `COMPANY_DAY_CLOSURE_MANIFEST`. `[Phase 1 spec v7 §11.0]`

D16 remains load-bearing. POS-core must work in a POS-only deployment. Any Treasury operational effect is a pluggable bridge resolved through the existing projector seam and `ModuleActivationResolver`; no fiscal engine, outbox ingestor, strict parser, or POS-core projector may import or call Treasury operational services directly. `[SoT §13.6, D16; Roadmap v2 "Locked inputs every phase inherits"]`

Reconciliation classification:

- `ACCOUNT_PAYMENT` authoring and receipt printing: `offline_authoritative`.
- Customer mirror freshness and printed balance snapshot: `offline_authoritative` at seal time, with explicit staleness metadata.
- Treasury `Payment` projection and FIFO allocation: `server_reconciles`.
- Open-document allocation detail: not mirrored to the device in Phase 2; the server reconciles it after sync. `[Roadmap v2 §Phase 2; SoT §9.x]`

## 2. Codebase Facts

Existing Phase 1 surfaces:

- Server and device enum vocabularies already reserve `ACCOUNT_PAYMENT`; both payload registries currently treat it as reserved but not implemented. `[apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php; apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php; apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts]`
- Device `FiscalEventEngine.append()` and the `/api/v1/pos/sync/fiscal-events` ingest path already support device-authored events once the registry, payload DTOs, and validators are extended. `[Phase 1 spec v7 §5.0, §7.2, §11.2-§11.4]`
- `Partner` already has `receivable_balance`, `credit_balance`, and `balance_updated_at` fields. `[apps/api/app/Modules/Partner/Domain/Partner.php; codebase reality audit §2.5]`
- The POS has no local customer/partner mirror table. `[codebase reality audit §2.3]`
- Offline POS cannot create Treasury `Payment` rows locally. `ACCOUNT_PAYMENT` must be a sealed local fiscal event plus local POS state; the server creates Treasury rows as a projection. `[codebase reality audit §2.3]`
- `Payment.origin` and `Payment.fiscal_event_id` already exist from Phase 1 and are in the Treasury-owned `payments` table with the correct FK direction (`payments → fiscal_events`). `[Roadmap v2 §Phase 1; apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php]`
- `PaymentAllocationService::applyAllocation()` still depends on `Auth::user()`-era controller context and `CompanyContext`; it needs an explicit command DTO / actor refactor before fiscal replay can invoke it safely. `[codebase reality audit §2.2; apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php]`

## 3. Phase 2 Deliverables

Phase 2 ships five bounded pieces:

1. POS customer mirror: local SQLite mirror of server `Partner` rows needed for offline search, attach, and balance snapshots.
2. Customer search/create/attach UX in the Tauri POS.
3. `ACCOUNT_PAYMENT` fiscal event: device-authored, sealed through the Phase 1 engine, synced through the Phase 1 fiscal-event endpoint.
4. `ACCOUNT_PAYMENT_RECEIPT` printable projection: POS-core, always available in POS-only and Treasury-active deployments.
5. Treasury bridge: optional projector that creates a Treasury `Payment` with `origin='pos'`, `fiscal_event_id` set, then performs FIFO allocation through the refactored allocation service when Treasury is active.

Out of scope:

- Charge-to-account / AR-creating sale flow (`ACCOUNT_CHARGE`) — Phase 3.
- Full open-document mirroring to POS — Phase 2 prints a balance snapshot; server reconciles allocation detail.
- ZATCA Tax Invoice / B2B invoice path — deferred by owner D8.
- Web POS device-authority parity — deferred parity work.
- AML national identity validation — not covered by existing `TaxIdValidationService`; defer unless owner separately scopes it.

## 4. POS Customer Mirror

Add a local SQLite table owned by the POS app. Suggested table name: `customers`.

Required mirror fields:

- `id` — server `partners.id`, UUID.
- `tenant_id`, `company_id` — required on every row; sync must reject cross-tenant/company payload drift.
- `name`.
- `phone`, `email` — nullable searchable fields if present on Partner.
- `tax_number` / VAT or fiscal identifier snapshot if present.
- `customer_category` — enough to distinguish B2C/B2B where the server exposes it; Phase 2 UX is B2C-centric but must not destroy existing categories.
- `receivable_balance`, `credit_balance` — bcformat strings at company currency scale.
- `balance_updated_at` — server timestamp for the balance snapshot.
- `sync_version` or `updated_at` — monotonic freshness anchor.
- `deleted_at` or `is_active` — so inactive customers disappear from search without orphaning historical local receipts.

Required local search indexes:

- `(tenant_id, company_id, name)`.
- `(tenant_id, company_id, phone)`.
- `(tenant_id, company_id, tax_number)` where the column exists.

Freshness semantics:

- The POS stores `customer_balance_stale: boolean` on the selected customer view when `balance_updated_at` is older than the configured threshold or the mirror has never fully synced.
- The event payload records the exact snapshot used at seal time: previous receivable, previous credit, net balance before payment, payment amount, and projected local remaining balance.
- The receipt print must visibly mark the balance as stale when stale at seal time.

Sync mechanism:

- Add a server pull endpoint or extend the existing reference-data pull surface to return customers scoped by `(tenant_id, company_id)` and changed since a cursor.
- The POS mirror writer must be upsert-only by `(tenant_id, company_id, id)` and must never merge rows across tenants or companies.
- Minimum offline customer create is allowed but must create a local pending customer with a deterministic client UUID and sync it before or alongside the `ACCOUNT_PAYMENT`. If server-side conflict resolution changes the canonical customer ID, the sync response must persist an alias mapping so historical local fiscal events remain tied to their sealed `customer_id`.
- Server fiscal-event ingest must accept a sealed `ACCOUNT_PAYMENT` whose customer is still `pending_create` because the event is factual and device-authored. POS-core projection can print/store it from the sealed snapshot. The Treasury bridge must wait or dead-letter with a typed `server_reconciles` reason until the pending customer has a tenant/company-scoped server `Partner` alias; it must never create a Treasury `Payment` against an unresolved or cross-company customer.

## 5. Customer Search / Create / Attach UX

The POS checkout surface gains a customer attach control optimized for repeated counter service:

- Search by phone, name, and account/tax identifier.
- Show balance summary: receivable, credit, net due, `balance_updated_at`, stale marker.
- Allow minimum-field create: name plus at least one configured contact key. Required country-specific tax fields are **not** forced for B2C attach unless the deployment requires them.
- Attach customer to a sale or an account payment.
- Detach before seal.

Fail-loud rules:

- A stale mirror does not silently block an account payment, but the stale state must be recorded in the sealed event and printed.
- A missing customer ID, missing tenant/company, or ambiguous alias mapping blocks sealing with a typed device-side error.
- Customer attach never causes the fiscal engine to call Customer/Partner/Treasury modules at seal time; all customer facts in the event are sealed snapshots.

## 6. ACCOUNT_PAYMENT Payload Contract

`ACCOUNT_PAYMENT` starts at `event_version: 1`. The event is atomic and rides the same fiscal chain as `SALE_RECEIPT`; there is no dual chain. `[SoT D6, D10, D14; owner D2/D4]`

The payload follows the Phase 1 `SALE_RECEIPT` precedent: a compliance-rich multi-country superset, not the minimum viable shape. It is deterministic JSON canonicalized by the same JCS-conformant encoder and must be mirrored byte-for-byte between TS and PHP. `[synthesis v5; Phase 1 spec v7 §11.2-§11.4]`

Required top-level keys. The canonical encoder sorts object keys in the byte representation; the list below is grouped by meaning, not by display order:

1. `account_payment_uuid` — UUID of the local payment event/business receipt.
2. `business_date` — POS business date.
3. `cashier_id`.
4. `cashier_name`.
5. `currency_code`.
6. `currency_scale`.
7. `customer` — sealed customer snapshot.
8. `event_time_device`.
9. `notes` — nullable.
10. `payment` — payment method/instrument block.
11. `receipt_type_code` — fixed `ACCOUNT_PAYMENT`.
12. `seller` — same seller block contract as `SALE_RECEIPT`.
13. `shift_id`.
14. `terminal_id`.
15. `training_flag`.
16. `treasury_allocation_policy` — fixed `FIFO` for Phase 2 unless server config explicitly later allows another policy.
17. `local_balance_snapshot` — local balance before/after payment at seal time.
18. `staleness` — balance/customer mirror freshness metadata.
19. `references` — nullable future-proof block for related fiscal events/documents.
20. `regime_extensions` — nullable object for jurisdiction-specific optional data.

Nested `customer` block:

- `customer_id` — server Partner UUID or local pending customer UUID.
- `customer_sync_status` — `synced` or `pending_create`.
- `name`.
- `phone` — nullable.
- `email` — nullable.
- `tax_number` — nullable.
- `address` — nullable object using the same address shape as `SALE_RECEIPT`.
- `customer_category` — nullable string snapshot.

Nested `payment` block:

- `amount` — non-negative bcformat string at `currency_scale`; must be greater than zero unless `training_flag=true`.
- `method_code` — mirrored setup/reference data code.
- `repository_id` — nullable; if present, mirror reference-data ID.
- `instrument_type` — nullable enum compatible with `SALE_RECEIPT` instrument semantics.
- `instrument_serial` — nullable.
- `foreign_currency_code` — nullable.
- `foreign_currency_amount` — nullable; required iff `foreign_currency_code` is non-null.

Nested `local_balance_snapshot` block:

- `receivable_balance_before`.
- `credit_balance_before`.
- `net_balance_before`.
- `payment_amount`.
- `projected_receivable_balance_after`.
- `projected_credit_balance_after`.
- `projected_net_balance_after`.
- `balance_updated_at`.

Nested `staleness` block:

- `customer_snapshot_stale` — boolean.
- `balance_snapshot_stale` — boolean.
- `mirror_last_synced_at` — nullable timestamp.
- `staleness_reason` — nullable enum string, e.g. `never_synced`, `older_than_threshold`, `server_conflict_pending`.

Nested `references` block:

- `related_sale_receipt_event_id` — nullable; used only if the counter workflow explicitly ties a payment to a just-completed sale.
- `server_customer_alias_id` — nullable; populated after server reconciliation only in an `ACCOUNT_PAYMENT_RECONCILED` follow-up event, not mutated into the original event.
- `external_reference` — nullable operator-entered reference.

`regime_extensions` is nullable in Phase 2. The required base payload already covers NF525/Tunisia immediate evidence. Future ZATCA/DE/IT data can extend through this object or later event types without changing the v1 base keys before go-live.

## 7. Per-Regime Field Analysis

NF525 / France immediate:

- Needs immutable ticket evidence with date/time, seller identity, operator, terminal, sequential fiscal chain, payment method, amount, and auditability. The Phase 1 engine supplies sequence, hash chain, canonical bytes, ingest verification, and NF525 export foundations. The `ACCOUNT_PAYMENT` payload supplies seller, cashier, terminal, payment amount/method, customer snapshot, and balance before/after.
- Seller tax number uses the same `seller.tax_number` block as `SALE_RECEIPT`. Strict FR SIRET validation remains Phase 1.5 task 2 and must not be guessed here.
- The printable `ACCOUNT_PAYMENT_RECEIPT` is a receipt for money received toward an account, not a VAT sale ticket; it must avoid VAT line semantics unless a later jurisdiction requires a specific display field.

Tunisia immediate:

- Owner D9 sets Tunisia priority; the NF525-style superset remains the baseline. The seller block includes tax jurisdiction country and tax number; the ACCOUNT_PAYMENT receipt includes amount, method, customer snapshot, and timestamp.
- TN matricule fiscal strict validation is blocked on accountant confirmation in Phase 1.5 task 2. Until that lands, Phase 2 code may only reuse the current universal validator and must keep the launch gate visible.

ZATCA / Saudi deferred:

- Phase 2 is B2C Simplified only via Tauri POS. `ACCOUNT_PAYMENT` does not implement a B2B Tax Invoice path and does not satisfy ZATCA Tax Invoice requirements. Keep nullable future fields available for seller/customer identifiers, but do not wire a ZATCA signing provider in Phase 2.

Germany deferred:

- German TSE signature/provider remains future work. `ACCOUNT_PAYMENT` keeps terminal, cashier, seller, payment method, amount, and canonical byte evidence so a future TSE projector/signature provider has the needed inputs. Do not implement TSE in Phase 2.

Italy deferred:

- Italy-specific lottery/codice fiscale/RT semantics are not implemented for ACCOUNT_PAYMENT in Phase 2. Customer tax fields remain snapshots; no Italy-specific validation or export path ships now.

## 8. Printable ACCOUNT_PAYMENT_RECEIPT

The printable is POS-core and must work even when Treasury is inactive.

Required display fields:

- Receipt title: `ACCOUNT PAYMENT RECEIPT` or localized equivalent.
- Seller name, tax number, address.
- Receipt number / `account_payment_uuid`.
- Fiscal event ID or QR token linking to the verified event where the existing print framework supports it.
- Terminal ID, cashier name, shift ID.
- Event time and business date.
- Customer name and optional phone/account identifier.
- Payment method, instrument serial if present, amount and currency.
- Balance before, payment amount, projected remaining balance.
- Staleness marker if customer or balance snapshot was stale.
- Training marker when `training_flag=true`.

The printable must read from the sealed payload or POS-core projection, not from a live Partner balance lookup. Server-side exports may use canonical bytes through the existing reader pattern.

## 9. Server Projection

POS-core projection:

- Add an always-active `AccountPaymentReceiptProjection` or equivalent POS-core projector for `ACCOUNT_PAYMENT`.
- It records the printable/projection row idempotently by `fiscal_event_id`.
- It must not import Treasury, Accounting, Customer, Contact, or B2B modules. Customer facts come from the sealed payload only.
- It must fail loud on malformed payloads or missing required references; projection failures become typed dead-lettered projection rows, not silent skips.

Treasury bridge:

- Add `TreasuryAccountPaymentBridge` implementing `FiscalEventProjector`.
- `handlesEventType()` returns true for `ACCOUNT_PAYMENT`.
- `requiresModule()` returns canonical `'Treasury'`; the existing registry and resolver gate execution.
- The bridge creates exactly one Treasury `Payment` row per fiscal event, idempotent by `fiscal_event_id` plus tenant/company scope.
- The `Payment` row sets `origin = PaymentOrigin::Pos`, `fiscal_event_id = $event->id`, `tenant_id`, `company_id`, `partner_id`, amount, currency, repository/payment method, date, and explicit actor metadata.
- Every FK lookup (`Partner`, payment method, repository, created_by/actor) is scoped by both `tenant_id` and `company_id` where the target table carries both. Missing or cross-tenant references throw typed projection exceptions.
- FIFO allocation is invoked only after the Payment exists and only through a refactored `PaymentAllocationService` API that accepts an explicit command DTO: actor, tenant, company, payment ID, allocation method, and source context. No `Auth::user()`, `app()`, `App::make()`, or `resolve()` is permitted.

Bridge idempotency:

- If a projected Payment with the event's `fiscal_event_id` already exists for the same tenant/company, return success without creating a duplicate.
- If a conflicting Payment exists with the same `fiscal_event_id` but different tenant/company/amount/customer, fail loud and dead-letter the projection.
- Allocation retry must be idempotent: existing allocations are detected and not duplicated.

## 10. Sync And Reconciliation

Device sequence:

1. Customer is selected or created locally.
2. POS captures payment method and amount.
3. POS computes balance snapshot and staleness.
4. Device builds the ACCOUNT_PAYMENT payload.
5. Device calls `FiscalEventEngine.append('ACCOUNT_PAYMENT', payload)`.
6. Device stores local printable receipt/projection and cash-drawer state in the same user-visible operation.
7. Existing fiscal-event sync flushes the sealed event to `/api/v1/pos/sync/fiscal-events`.

Server sequence:

1. `OutboxIngestor` verifies hash linkage and canonical bytes.
2. Strict parser validates the ACCOUNT_PAYMENT payload and stores parse status.
3. Projection registry creates active projection rows.
4. POS-core receipt projection applies in every deployment.
5. Treasury bridge applies only when `ModuleActivationResolver` reports Treasury active.

Reconciliation outcomes:

- If Treasury inactive: fiscal event is verified, printable projection exists, Treasury projection row is not created.
- If Treasury active and allocation succeeds: Payment + FIFO allocations exist; projection applied.
- If Treasury active and allocation cannot complete due to stale/open-document state: Payment remains linked to the fiscal event; allocation projection dead-letters or records a typed `server_reconciles` pending status according to the implementation plan. The sealed event is not rejected or rewritten.

## 11. Tests And Gates

Round-1 test matrix must include:

- TS/PHP payload registry accepts ACCOUNT_PAYMENT at version 1 and keeps server-only set unchanged.
- TS/PHP canonical byte parity for golden ACCOUNT_PAYMENT fixtures.
- Strict parser accepts every positive fixture and rejects every malformed required field.
- Constraint validator exhaustively covers discriminated-union variants: synced vs pending customer, stale vs fresh balance, local vs foreign currency payment, nullable vs populated references.
- Device engine appends ACCOUNT_PAYMENT and rejects missing customer, zero amount, cross-company mirror row, and stale alias conflict.
- POS customer mirror migration/upsert/search tests, including cross-tenant/company isolation.
- POS customer search/create/attach UX tests.
- POS-only deployment test: Treasury inactive, ACCOUNT_PAYMENT projects printable receipt and skips Treasury bridge.
- Treasury-active test: creates Payment with `origin='pos'`, `fiscal_event_id`, tenant/company scoped FKs, and FIFO allocation.
- Bridge idempotency test: projection retry does not duplicate Payment or allocations.
- Fail-loud tests for missing Partner, wrong-company Partner, missing repository/payment method, and allocation exception.
- Full-flow closure test: device authors ACCOUNT_PAYMENT → seals → syncs → server ingests/parses/projects → printable reads canonical → byte equivalence holds.

Required gates per task:

- `./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/`
- `./vendor/bin/phpstan analyse --level=8 <touched PHP paths>`
- `./vendor/bin/pint --test <touched PHP files>` or full Pint when touched set is broad.
- `pnpm test` and `pnpm lint` in `apps/pos`.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh`.
- Any new ACCOUNT_PAYMENT drift/sentinel gates introduced by the plan.

## 12. Standing Pattern Carry-Forward

Mandatory review axes for every Phase 2 task:

- Cross-tenant FK safety: every server lookup introduced by customer mirror, POS-core projection, and Treasury bridge is tenant/company scoped.
- Fail-loud over silent downgrade: projection errors throw typed exceptions and dead-letter observably.
- Dead-path rebuild: every new DTO, projector, route, migration, and UI path has a live caller and a test.
- Discriminated-union matrix completeness in round 1.
- Contract drift prevention: TS and PHP payload contracts stay byte-equivalent with golden tests and docs matching implementation.
- Per-method `markTestSkipped()` only; never class-level.
- Skip-citation accuracy.
- CLAUDE.md rule 13: constructor injection only; no `app()`, `App::make()`, or `resolve()`.
- D16 guard: POS-core projectors must not import Treasury/Customer/B2B/Accounting operational modules; Treasury bridge lives behind the module resolver.
- R2 fixes require fresh self-review and second-pass review.

## 13. Implementation Plan Inputs

The Phase 2 implementation plan should split work into these atomic tasks:

1. ACCOUNT_PAYMENT payload contract and TS/PHP drift gates.
2. POS customer mirror schema, repository, sync pull, and search indexes.
3. Customer search/create/attach UI and local pending-customer alias handling.
4. Device ACCOUNT_PAYMENT authoring flow and printable receipt.
5. Server strict parser / payload DTO / POS-core ACCOUNT_PAYMENT receipt projection.
6. PaymentAllocationService command-DTO refactor with explicit actor/context and no Auth dependency for replay.
7. TreasuryAccountPaymentBridge with idempotent Payment creation and FIFO allocation.
8. Full-flow and POS-only closure tests.

Phase 1.5 task 2 remains a parallel launch blocker, not an implementation-detail footnote. The plan must keep it visible until accountant confirmation lands.
