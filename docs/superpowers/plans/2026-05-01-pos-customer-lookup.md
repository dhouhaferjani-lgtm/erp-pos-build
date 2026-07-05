# POS Customer Lookup — Task Plan

**Spec:** `docs/superpowers/specs/2026-05-01-pos-customer-lookup-design.md`
**Status:** Draft
**Estimated effort:** ~23 hours (~3 days at standard pace)
**Dependency:** Tenant-isolation A+B (or at minimum the scoped-`exists` helper from B) must land first.

## Decisions block (locked)

- Lookup keys: phone, mobile, email, loyalty card. Exact match only in v1; prefix match on display name in v1.5.
- Identifier source for loyalty card: `loyalty_members.external_id` (no new column).
- `billing_partner_id` is a request-side override only — no persisted "was overridden" flag on `pos_receipts`.
- Cashier permission gating: reuses `pos.search_customer_recent_purchases` and `pos.search_customer_full_history`. No new permission.
- Inline contact create: minimum one of phone/email/loyalty_card required. Lax format validation at create, normalize at storage.
- `contacts.company_id` migration: make nullable. No data backfill needed (existing rows keep their FK).
- Forward-compat: spec is additive; no fiscal-hash impact, no v2-path change.

## Phases

### Phase A — Schema + backend mirror endpoints

| # | Task | Files | Hours |
|---|---|---|---|
| A.1 | Migration: make `contacts.company_id` nullable | `apps/api/database/migrations/2026_05_02_xxxxxx_make_contact_company_id_nullable.php` | 0.5 |
| A.2 | `ContactMirrorRowDto` + `ContactMirrorService::pull` (paginated, `?updated_since=`, joins `loyalty_members.external_id` as `loyalty_card_number`) | `apps/api/app/Modules/POS/Application/{DTOs,Services}/` | 2 |
| A.3 | `ContactMirrorController` + route registration | `apps/api/app/Modules/POS/Presentation/Controllers/ContactMirrorController.php`; `apps/api/app/Modules/POS/routes.php` | 1 |
| A.4 | `ContactMirrorEndpointTest` (pagination, `?updated_since`, tenant scope, permission, loyalty_card_number resolution) | `apps/api/tests/Feature/POS/ContactMirrorEndpointTest.php` | 1 |
| A.5 | `PartnerMirrorRowDto` + `PartnerMirrorService::pull` (paginated, includes `linked_contacts` list with `is_primary`/`is_invoice_contact`/`job_title`) | as above | 2 |
| A.6 | `PartnerMirrorController` + route | as above | 0.5 |
| A.7 | `PartnerMirrorEndpointTest` (same coverage as A.4) | `apps/api/tests/Feature/POS/PartnerMirrorEndpointTest.php` | 1 |

**Phase A exit gate:** all four endpoint tests green. PHPStan level 8 zero errors. Pint clean.

### Phase B — Inline contact creation

| # | Task | Files | Hours |
|---|---|---|---|
| B.1 | `InlineContactRequest` + validator (min one of phone/email/loyalty_card; lax format) | `apps/api/app/Modules/POS/Presentation/Requests/InlineContactRequest.php` | 0.5 |
| B.2 | `InlineContactService::create` (creates `contacts` row with `company_id=NULL`; if `loyalty_card` present, also creates `loyalty_members` polymorphic to contact) | `apps/api/app/Modules/POS/Application/Services/InlineContactService.php` | 1.5 |
| B.3 | `InlineContactController` + route | as above | 0.5 |
| B.4 | `InlineContactCreationTest` (min-field, loyalty side-effect, tenant scope, returns full row) | `apps/api/tests/Feature/POS/InlineContactCreationTest.php` | 1.5 |

**Phase B exit gate:** test green. Backend round-trip (create + retrieve via mirror endpoint) works.

### Phase C — `billing_partner_id` override on receipt creation

| # | Task | Files | Hours |
|---|---|---|---|
| C.1 | Add `billing_partner_id` to `StoreReceiptRequest` rules (nullable, scoped-`exists` helper from tenant-isolation B) | `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php` | 0.5 |
| C.2 | `ReceiptCreationService::createReceipt` consumes `billing_partner_id`: if present, use as `partner_id` directly; else fall back to existing auto-derive | `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` | 1 |
| C.3 | `ReceiptCreationBillingPartnerOverrideTest` (5 scenarios: honored, cross-tenant 422, null+contact = auto-derive, anonymous, **fixture-08 hash unchanged**) | `apps/api/tests/Feature/POS/ReceiptCreationBillingPartnerOverrideTest.php` | 1.5 |

**Phase C exit gate:** override works, hash invariant locked, all existing receipt tests still pass.

### Phase D — POS SQLite mirror schema

| # | Task | Files | Hours |
|---|---|---|---|
| D.1 | SQLite migration v29: create `contacts_mirror`, `partners_mirror`, `party_contacts_mirror` with indexes; add `pending_contact_inserts` for offline-create queue | `apps/pos/src/lib/db/migrations.ts` | 1 |
| D.2 | Migration replay test (fresh + populated, mirroring R1's pattern from PR #77) | `apps/pos/src/lib/db/__tests__/migrations.v29.test.ts` | 1 |

**Phase D exit gate:** migration replay tests green. Schema matches §4.3 of spec.

### Phase E — POS sync pull + push tasks

| # | Task | Files | Hours |
|---|---|---|---|
| E.1 | Add `pullContactsMirror` + `pullPartnersMirror` tasks to `runFullSync` (same pattern as `pullReceiptQrIndex`) | `apps/pos/src/lib/sync/syncService.ts` | 1 |
| E.2 | Local upsert helpers: `upsertContacts`, `upsertPartners`, `upsertPartyContacts` | `apps/pos/src/lib/offline/contactRepository.ts` (new), extend `voucherRepository.ts` if shared helpers | 1 |
| E.3 | `pushPendingContactInserts` (drains `pending_contact_inserts` queue, posts to `/pos/contacts/inline`, reconciles local UUID to server UUID on success) | `apps/pos/src/lib/sync/syncService.ts` | 1 |
| E.4 | `syncService` test: pull populates mirrors, push drains queue, server-failure path keeps row pending | `apps/pos/src/lib/sync/__tests__/contactSync.test.ts` | 1 |

**Phase E exit gate:** sync round-trip works end-to-end against a mocked server.

### Phase F — POS local lookup queries

| # | Task | Files | Hours |
|---|---|---|---|
| F.1 | `findContactsByPhone(phone)`, `findContactsByEmail(email)`, `findContactsByLoyaltyCard(card)` — exact-match, tenant-doubled, with index hints | `apps/pos/src/lib/offline/contactRepository.ts` | 1 |
| F.2 | `getLinkedPartners(contactId)` — joins `partners_mirror` via `party_contacts_mirror` ordered by `is_primary DESC` | as above | 0.5 |
| F.3 | Phone normalization helper (E.164 if recognizable, digit-only fallback) | `apps/pos/src/lib/payment/phoneNormalization.ts` (new, reusable) | 0.5 |
| F.4 | Lookup-query tests (each lookup key, normalization edge cases, multi-result, no-result, tenant scope) | `apps/pos/src/lib/offline/__tests__/contactRepository.test.ts` | 1 |

**Phase F exit gate:** queries hit indexes (verify via `EXPLAIN QUERY PLAN`), all variants tested.

### Phase G — UI rebuild

| # | Task | Files | Hours |
|---|---|---|---|
| G.1 | `ReceiptLocatorScreen` — restore "Find by customer" tab, gate on `pos.search_customer_recent_purchases` | `apps/pos/src/components/pos/ReceiptLocatorScreen.tsx` | 1 |
| G.2 | `CustomerLookupTab` component — debounced input, result list rendering, contact card with linked partners + per-billing-option "Use" buttons | `apps/pos/src/components/pos/CustomerLookupTab.tsx` (new) | 2 |
| G.3 | "Use as personal" / "Use as <Business>" handlers — set `contact_id` + optional `billing_partner_id` on the active payment flow's request shape | as above + `paymentStore.ts` integration | 1 |
| G.4 | "Create new contact" inline form (modal or expansion within the tab) — validates min-field, calls `InlineContactService` via the local pending queue | `apps/pos/src/components/pos/InlineContactCreate.tsx` (new) | 1.5 |
| G.5 | i18n keys: `customerLookup.search.placeholder`, `.results.useAsPersonal`, `.results.useAs`, `.create.title`, `.create.minField`, en + fr | `apps/pos/src/locales/{en,fr}/pos.json` | 0.5 |
| G.6 | Component tests: search debounce, button-click flow, inline create, modal-fixed-size invariant (memory: `feedback_modal_fixed_size.md`) | `apps/pos/src/components/pos/__tests__/ReceiptLocatorScreen.customerTab.test.tsx` | 1 |

**Phase G exit gate:** UI tests green, manual smoke in dev env (open till, search, click, see receipt request shape carry both fields).

### Phase H — Integration + regression

| # | Task | Files | Hours |
|---|---|---|---|
| H.1 | End-to-end test: HomePage mounts, cashier searches by phone, picks "Use as personal", completes checkout, asserts `pos_receipts.contact_id` and `partner_id` correct | `apps/pos/src/components/pos/__tests__/HomePage.customerLookup.integration.test.tsx` | 1.5 |
| H.2 | Regression sweep: B4 negative tests pass, B5 voucher redemption with contact-bearing receipt works, fixture-01 + fixture-08 hashes unchanged, v2 path zero-line diff | scripted sweep | 1 |
| H.3 | Audit log assertion: every search emits a `customer_history_search_audits` row with the correct `query_kind` | extends Phase F tests or new test | 0.5 |

**Phase H exit gate:** full preflight green. Manual cashier-flow smoke. Ready for PR.

## Cross-cutting discipline

- TDD strict per task. Failing test FIRST.
- Constructor injection only (PHP). No `app()`. No `mixed`. PHPStan level 8 zero errors. Pint clean.
- TypeScript strict — no `any`. Web typecheck clean.
- Multi-tenant scoping uses the helper from tenant-isolation B (do NOT inline `where('tenant_id', ...)` chains; centralize via the helper).
- All commits co-authored: `Co-Authored-By: <model> <noreply@anthropic.com>`.
- One PR at the end, target `dev`. Title: `feat(pos): cashier customer lookup by phone/email/loyalty (refund-flow Phase 1 deferred)`.

## Risks + mitigations

| Risk | Mitigation |
|---|---|
| Tenant-isolation B helper not ready | Block this work until B's helper lands. Don't inline scoping. |
| Mirror payload size at scale (50K+ contacts) | Phase 1 ships with `?updated_since=` incremental sync; full re-pull only on first sync. Monitor real tenant data; if 50K+ becomes common, add server-side filter to recent-active contacts. |
| `loyalty_members.external_id` not unique within tenant | Verify with grep + a unit test asserting uniqueness expectation. If not unique, add a partial unique index migration. |
| Phone normalization mismatch between server and client | Single shared normalization rule, documented in spec §5.2 + lib at `apps/pos/src/lib/payment/phoneNormalization.ts` mirrored to PHP. Add a parity test like the `paymentMethodKind.parity.test.ts` pattern. |
| Audit page (existing in admin per refund-flow §6.5) doesn't render new query_kind values | Verify existing render logic accepts unknown enum values; if it crashes, ship a fallback before merging. |

## Forward-compat hooks

- The `billing_partner_id` request field is the same field the **B2B-invoice-handoff spec** will read; the handoff endpoint reads `pos_receipts.partner_id` (set via this spec's override) as the canonical billing entity.
- The `contacts_mirror` and `partners_mirror` tables are reusable by the **recap-invoice spec** for back-office partner-by-period queries (those will hit the canonical server, not the mirror, but the contact/partner identifiers stay consistent across both flows).
- Phase 2 (Otospex/automotive) extends the contact card with vehicle linkage. The mirror tables can be augmented additively later.
