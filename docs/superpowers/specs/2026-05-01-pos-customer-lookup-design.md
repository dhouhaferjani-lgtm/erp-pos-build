# POS Customer Lookup — Design

**Date:** 2026-05-01
**Status:** Draft
**Phase:** Refund Flow Phase 1 deferred item (re-tracked from M2-UI's Phase-2 misframing)
**Related specs (separate scope):**
- `pos-b2b-invoice-handoff-design.md` (to be drafted) — POS-side action that promotes a sale to a B2B invoice via the existing Document module.
- `pos-receipt-aggregation-recap-invoice-design.md` (to be drafted) — back-office `facture récapitulative` flow that consolidates a partner's receipts over a period into a single invoice.

This spec covers cashier-side **lookup only**. Issuing a B2B invoice and consolidating receipts into a `facture récapitulative` are explicitly out of scope and live in the two sibling specs above.

## 1. Why

Phase 1 of the refund flow shipped without a working "Find customer at the till" tab. The original PR #76 dropped a half-broken UUID-text input (commit `220dc4ea`) with a note pointing to "Phase 2 to rebuild." That framing was wrong — customer lookup is Phase 1 (standard retail, IziPOS) work, not Phase 2 (Otospex automotive). It was deferred for capacity reasons, not for vertical reasons.

Cashier needs to:
- Identify a returning customer for loyalty / personalization / refund-context (`pos.search_customer_recent_purchases`-bound flow already exists in the receipt locator).
- Use phone, email, or a loyalty identifier as the lookup key — not a partner UUID, which the cashier will never have at hand.
- See the result clearly enough to pick the right billing intent: anonymous walk-in (skip), identified personal (contact's own partner), or attached to a company (contact's primary linked party).

The fiscal context (verified in the 2026-05-01 regulatory + codebase investigation):
- **NF525 receipt is a fiscally complete document for B2C** in France. No buyer data is required on the receipt itself; that's an invoice requirement.
- **Tunisia has no NF525-equivalent and no e-invoice mandate for B2C retail.**
- **B2B sales always require a proper invoice in France** (CGI art. 289, no threshold). The receipt does not satisfy that requirement; the existing `Document` module does. Triggering the invoice from the till is a separate spec (`pos-b2b-invoice-handoff-design.md`).

So the POS lookup is a **B2C convenience feature**, not a fiscal-compliance feature. Scope shrinks accordingly.

## 2. Out of scope (explicit)

- Issuing a B2B invoice from the till. Sibling spec.
- Aggregating multiple receipts into a `facture récapitulative`. Sibling spec.
- Italy `fattura elettronica` on demand at the till (SDI flow). Long-term deferral; not shipping in Italy.
- Vehicle / workshop / service-bay lookup keys (Otospex/Phase 2).
- Restaurant-voucher lookup (Phase 2 per refund-flow plan `:1584`).
- Any change to fiscal-hash inputs or the receipt seal contract.

## 3. Constraints inherited from the refund flow

- **Offline-first.** The till must work with no network. Lookup must hit a local SQLite mirror, never a live API. Mirror is refreshed by the existing sync pipeline.
- **NF525 hash chain unchanged.** Customer lookup is post-receipt-creation metadata; the canonical hash input does not change. Fixture-01 and fixture-08 hashes stay byte-identical.
- **Multi-tenant scoping required.** Every lookup query must scope to the cashier's tenant + company. Aligns with `project_payment_method_tenant_isolation.md`'s sweep.
- **Permission gating preserved.** Existing permissions `pos.search_customer_recent_purchases` and `pos.search_customer_full_history` already model the search-window capability. Reuse.
- **Audit logging preserved.** Every lookup must emit a `customer_history_search` audit row (existing `customer_history_search_audits` table). Phase 1 already has this; we just feed the new flow through it.

## 4. Data model

### 4.1 Existing tables (no schema change unless flagged)

- `contacts` (`2026_03_10_100001_create_contacts_table.php`) — individuals. Indexed on `(tenant_id, phone)` and `(tenant_id, email)`. **One schema change required:** make `contacts.company_id` nullable (it's currently a non-nullable FK to the multi-tenant scoping `companies` table; this blocks creating a standalone B2C walk-in contact at the till). Migration is one line.
- `partners` (`2025_11_30_052119_create_partners_table.php`) — billing entities (customers, suppliers, both). Discriminated by `customer_category` (`individual | business`). Has `phone`, `email`, `vat_number`, `company_legal_name`.
- `party_contacts` (`2026_03_10_100002_create_party_contacts_table.php`) — pivot. Carries `is_primary`, `is_invoice_contact`, `is_delivery_contact`. **The `is_primary` flag is the auto-derive driver in `ReceiptCreationService::createReceipt`** (`:514-519`).
- `loyalty_members` (polymorphic, `2026_03_10_100004_make_loyalty_polymorphic.php`) — morphs to `Contact` or `Partner`. Identifier is `phone`, optional `external_id` (use as loyalty card).
- `pos_receipts` — already nullable on both `partner_id` and `contact_id`. Anonymous walk-in works today.
- `customer_history_search_audits` — every lookup is logged here. Reuse.
- `receipt_qr_index` — already mirrors `partner_id` to the till per M2-backend `5d1cee99`.

### 4.2 New schema additions

| Change | Rationale |
|---|---|
| `contacts.company_id` → nullable | Standalone B2C walk-in (no ERP-tenant attachment context) must be storable as a Contact. |
| Optional: `loyalty_members.card_number` (string, indexed `(tenant_id, card_number)` unique) | If we want a dedicated physical-card identifier separate from `external_id`. **Defer to v1.5** unless a customer specifically asks for printed cards. The existing `external_id` is sufficient for digital QR/loyalty-app flows. |

No new persisted column on `pos_receipts`. The existing `partner_id` (auto-derived) and `contact_id` (set explicitly by the cashier) are sufficient. **Billing-entity override is request-side only**, not a stored field — see §5.5.

### 4.3 New POS-side SQLite mirror tables

The till must look up offline. Mirror is refreshed by the existing sync pipeline (additive endpoints — see §6).

```sql
CREATE TABLE contacts_mirror (
  id TEXT PRIMARY KEY,             -- contact UUID
  tenant_id TEXT NOT NULL,
  display_name TEXT NOT NULL,      -- "{first_name} {last_name}"
  first_name TEXT,
  last_name TEXT,
  phone TEXT,
  mobile TEXT,
  email TEXT,
  loyalty_card_number TEXT,        -- pulled from loyalty_members.external_id when contact is enrolled
  is_active INTEGER NOT NULL DEFAULT 1,
  synced_at TEXT NOT NULL
);

CREATE INDEX contacts_mirror_phone_idx ON contacts_mirror(phone) WHERE phone IS NOT NULL;
CREATE INDEX contacts_mirror_mobile_idx ON contacts_mirror(mobile) WHERE mobile IS NOT NULL;
CREATE INDEX contacts_mirror_email_idx ON contacts_mirror(email) WHERE email IS NOT NULL;
CREATE INDEX contacts_mirror_card_idx ON contacts_mirror(loyalty_card_number) WHERE loyalty_card_number IS NOT NULL;

CREATE TABLE partners_mirror (
  id TEXT PRIMARY KEY,
  tenant_id TEXT NOT NULL,
  name TEXT NOT NULL,
  customer_category TEXT,          -- 'individual' | 'business' | NULL
  vat_number TEXT,
  company_legal_name TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  synced_at TEXT NOT NULL
);

CREATE TABLE party_contacts_mirror (
  party_id TEXT NOT NULL,          -- partners_mirror.id
  contact_id TEXT NOT NULL,        -- contacts_mirror.id
  is_primary INTEGER NOT NULL DEFAULT 0,
  is_invoice_contact INTEGER NOT NULL DEFAULT 0,
  job_title TEXT,
  PRIMARY KEY (party_id, contact_id)
);

CREATE INDEX party_contacts_mirror_contact_idx ON party_contacts_mirror(contact_id);
```

Sync follows the same `?updated_since=...` incremental pattern used by the existing receipt QR index.

## 5. Lookup flow

### 5.1 Cashier UX (single search field)

The receipt locator's "Find by customer" tab returns. UX:

1. Single text input. Placeholder: "Phone, email, or loyalty card."
2. As the cashier types ≥ 3 characters (Phase 1: exact match only — no prefix), search runs locally against `contacts_mirror`. Debounced 200ms.
3. Results render as a list of contact cards. Each card shows:
   - Contact display name + identifier match indicator (`📞 +33 6 12 34 56 78`, `✉️ jane@acme.fr`, `🎫 LC-2026-0123`).
   - Linked partners (from `party_contacts_mirror` + `partners_mirror`) — each shown with a category badge (`Individual` / `Business`) and the legal name if business.
   - One "Use" button per billing option:
     - "Use as personal" — sets `contact_id` on the receipt; `billing_partner_id` is set to the contact's individual partner if exists, else null.
     - "Use as <Business Name>" — sets `contact_id` and `billing_partner_id` to the business partner (overrides auto-derive).
4. If no result: option to "Create new contact" inline (lightweight form: first name, last name, phone, email, optional loyalty card). Saves to local SQLite immediately and queues a server upsert.

### 5.2 Lookup matching rules (Phase 1)

- **Exact match only** on phone, mobile, email, loyalty card. Whitespace + case-insensitive normalization. Phone numbers normalize via E.164 if recognizable, else fall back to digit-only stripped comparison.
- Phase 1.5 follow-up: **prefix match on display name** for cases where the cashier knows "Madame Dupont" but not the phone number.
- **Tenant filter** is implicit (mirror only contains the cashier's tenant data) but doubled at query time for defense-in-depth.

### 5.3 Audit logging

Each lookup emits a `customer_history_search_audits` row with:
- `terminal_id`, `operator_id`, `tenant_id`, `company_id`
- `query_kind = 'phone' | 'email' | 'loyalty_card' | 'name_prefix'`
- `match_count` (zero, one, many)
- `selected_contact_id` (null if no card was clicked)

Reuses the existing audit table — no schema change. The query string itself is **not stored** (privacy — the existing pattern already does this and the audit page docs §6.5 of the spec confirm).

### 5.4 Permission gating

- `pos.search_customer_recent_purchases` — required to open the lookup tab. Search window inherited from existing `searchWindowDays` plumbing (which M2-UI removed; restore from the same code that referenced `findRecentReceiptsByPartner`).
- `pos.search_customer_full_history` — required to bypass the search window cap (full local-mirror visibility regardless of receipt date).
- No new permission required.

### 5.5 Billing-entity selection (`billing_partner_id` override, request-side only)

When the cashier picks a result card, the receipt creation request includes:
```ts
{
  contact_id: "<contact uuid>",
  billing_partner_id: "<partner uuid> | null"
}
```

Server-side handling in `ReceiptCreationService::createReceipt`:
- If `billing_partner_id` is provided, use it as `receipt.partner_id`. Skip the auto-derive.
- If `billing_partner_id` is null AND `contact_id` is provided, fall back to the existing auto-derive (`contact.parties()->wherePivot('is_primary', true)->first()`). This preserves backward compatibility with all current callers.
- If neither, anonymous receipt as today.

Validation:
- If `billing_partner_id` is set, it must exist within the cashier's `tenant_id` + `company_id`. Use the scoped-`exists` helper from the in-flight tenant-isolation work (`project_payment_method_tenant_isolation.md`). **This spec depends on that helper landing first.**
- If `billing_partner_id` is set without `contact_id`, that's allowed (cashier looked up the partner directly somehow — though this Phase doesn't expose a UI for it). Validator does not require both.

**No persisted "this was overridden" flag.** The receipt's `partner_id` IS the billing entity. Audit reconstruction works via a join: any receipt where `contact_id IS NOT NULL` and `partner_id != contact.primary_party_id` is an override.

## 6. Backend additions

### 6.1 Mirror endpoints

Two new GET endpoints, both incremental, both tenant-scoped, both behind `pos.terminal` middleware (same auth as the existing receipt-qr-index endpoint):

- `GET /api/v1/pos/contacts/mirror?updated_since=<iso8601>&page=<int>` — returns 100 contacts per page with their loyalty_card_number resolved from `loyalty_members.external_id`.
- `GET /api/v1/pos/partners/mirror?updated_since=<iso8601>&page=<int>` — returns 100 partners with category + vat + legal name + a `linked_contacts` list of `{contact_id, is_primary, is_invoice_contact, job_title}`.

Both endpoints reuse the existing rate-limiting middleware and the receipt-qr-index DTO pattern. Both must use the scoped-`exists` helper for any input validation. **DTOs include only the fields needed for till lookup** — no balance, no addresses, no credit limit (B2C convenience feature; full partner data lives in the back office).

### 6.2 ReceiptCreationService changes

- Accept the new `billing_partner_id` field on `StoreReceiptRequest`.
- Tenant-scoped existence check on the partner.
- Use it directly as `receipt.partner_id` when present; fall back to existing auto-derive otherwise.
- Existing tests (`StoreReceiptPaymentsInstrumentBindingTest`, `OfflineV3CutoverSyncTest`) MUST continue to pass — they don't supply `billing_partner_id`, the auto-derive path stays default.

### 6.3 Inline contact creation

- New endpoint: `POST /api/v1/pos/contacts/inline` for the till's "Create new contact" UX.
- Validates: first_name, last_name, optional phone, optional email, optional loyalty_card. At least one of phone/email/loyalty_card required.
- Creates a `contacts` row with `company_id = NULL` (the schema migration enables this), no `party_contacts` link.
- If a `loyalty_card` is provided, also creates a `loyalty_members` row morphing to the new contact, with `external_id = <card>`.
- Returns the contact row for immediate local mirror upsert.

### 6.4 POS sync layer additions

- New pull tasks in `runFullSync` for the two mirror endpoints. Same pattern as the existing receipt-qr-index pull (page through, upsert on conflict).
- Inline contact creates are queued in a local pending table (`pending_contact_inserts`) and pushed during sync; on server success the local row's `id` is reconciled to the server-assigned UUID.

## 7. Test plan

### 7.1 Backend unit + feature

- `ContactMirrorEndpointTest`: pagination, `?updated_since=` filter, tenant scope (cross-tenant query returns empty), permission (operator without `pos.terminal` gets 403), `loyalty_card_number` resolution from `loyalty_members.external_id`.
- `PartnerMirrorEndpointTest`: same.
- `ReceiptCreationBillingPartnerOverrideTest`:
  - `billing_partner_id` honored — no auto-derive.
  - `billing_partner_id` cross-tenant → 422.
  - `billing_partner_id = null` + `contact_id` → existing auto-derive.
  - Anonymous walk-in (both null) → existing behavior.
  - **Regression: fixture-08 hash unchanged when `billing_partner_id` is provided** (proves the override doesn't leak into the canonical hash input).
- `InlineContactCreationTest`: minimum-field validation, loyalty member side-effect, tenant scope.

### 7.2 POS frontend (Vitest)

- `ContactsMirrorRepository.test.ts`: SQLite indexes hit on phone/email/card lookups. Exact-match semantics. Tenant filter doubled.
- `ReceiptLocatorScreen.customerTab.test.tsx`: search debounce, result rendering, "Use as personal" vs "Use as <Business>" button click → correct `billing_partner_id` flows through to `processOnlineCheckout` / `processCashCheckout`.
- `ReceiptLocatorScreen.inlineCreate.test.tsx`: form validation, success path, optimistic local upsert.
- Anonymous-walk-in regression: existing tests for receipts without `contact_id` continue to pass.

### 7.3 End-to-end regression

- Fixture-01 hash unchanged.
- Fixture-08 hash unchanged.
- v2 path bit-for-bit unchanged.
- B4 negative tests pass (`store_voucher` with null instrument fields → 422).
- B5 voucher redemption flow still works end-to-end with a contact-bearing receipt.

## 8. Migration / rollout

1. Schema migration: `contacts.company_id` → nullable. One line.
2. Backfill check: any existing `contacts` rows with `company_id` pointing at the tenant-scoping `companies` table stay attached as before; new contacts created via the inline POS flow have `company_id = NULL`. No data migration needed.
3. Mirror endpoints + sync pipeline tasks ship behind a feature flag (`pos.customer_lookup_enabled`, defaults true on tenants where the cashier holds `pos.search_customer_recent_purchases`).
4. UI tab returns to `ReceiptLocatorScreen` — feature-flag-gated.

No fiscal-hash impact. No NF525 attestation impact (the receipt seal is unchanged).

## 9. Effort estimate

| Layer | Files | Hours |
|---|---|---|
| Schema migration (`contacts.company_id` nullable) | 1 | 0.5 |
| Backend mirror endpoints + DTOs + tests | ~6 | 4 |
| `ReceiptCreationService` `billing_partner_id` handling + tests | 2 | 2 |
| Inline contact creation endpoint + test | 2 | 2 |
| POS SQLite migration (mirror tables + indexes) | 1 | 1 |
| POS sync layer pull tasks | 1 | 2 |
| POS local repository + lookup queries + tests | 2 | 3 |
| `ReceiptLocatorScreen` UI rebuild | 2 | 4 |
| i18n keys (en + fr) | 2 | 0.5 |
| Integration tests + bug-bash | — | 4 |
| **Total** | **~19** | **~23 hours** (~3 days at standard pace) |

## 10. Dependencies

- **Tenant-isolation A+B work must land first** (or at minimum the scoped-`exists` helper from B). This spec uses it for `billing_partner_id` validation and for the mirror endpoint scoping.
- Refund flow Phase 1 (PR #76) must be on dev — already merged.
- POS performance work can happen in parallel; no shared files.

## 11. Open questions for owner

1. **Loyalty card identifier** — use `loyalty_members.external_id` (already exists, no migration) or add a dedicated `loyalty_members.card_number` column? Recommend `external_id` for v1; revisit if printed-card workflows surface.
2. **Inline contact create — phone/email format validation strictness?** Strict (E.164 / RFC 5322) blocks edge cases (foreign tourists, partial info). Lax accepts more but lets bad data through. Recommend lax with normalization at storage time, fix on the back-office partner-merge flow.
3. **Search window default for cashiers without full-history permission** — what's the right cap? Existing `searchWindowDays` plumbing was 30 days; that probably remains the right Phase 1 default.

## 12. Forward compatibility

- The `billing_partner_id` request field is already shaped to accept the future B2B-invoice-handoff flow's needs. The handoff spec will use the same field — POS sets it; Document module reads it from the receipt.
- The mirror endpoints can later expose additional fields (vehicle linkage for Otospex / Phase 2; nominal-vs-personal toggles for restaurant verticals). Additive only.
- The `facture récapitulative` flow consumes `pos_receipts.partner_id` as its query key — this spec ensures `partner_id` is correctly populated whenever the cashier identifies the buyer, which is the precondition for the recap-invoice spec to work.
