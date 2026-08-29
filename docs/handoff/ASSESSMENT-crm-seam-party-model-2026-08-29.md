# Assessment — does the B-18 party model leave a clean seam for a future marketing CRM? (2026-08-29, Session H)

**Owner's question.** "All I need right now is that the system sells to companies and private persons. I don't want to be blocked when I add a CRM later for marketing — email, WhatsApp, Facebook, etc."

**Verdict: the target model (spec R1–R5) does not block a marketing CRM; it is the shape every marketing CRM attaches to.** Nothing in Phase 1 or Phase 2 has to change. Four cheap *reservations* are folded into Phase 2 so the CRM lands as an additive module later, and five things are explicitly NOT built now. Evidence is a read-only census of the code at `23b1b8a65` (citations below).

## 1. What exists today (census, file:line)

| Area | Fact |
|---|---|
| Marketing / messaging modules | **None.** 47 modules under `apps/api/app/Modules`; no Marketing / Campaign / Segment / Tag / Consent / WhatsApp / SMS. `Communication/` = 4 files, document-invoice email only (`Communication/Application/Services/DocumentEmailService.php:60,118,139-149`, recipient = `document->partner->email`). `Notification/` = in-app inbox for **users**, not customers (`Notification/Presentation/routes.php:22-30`). |
| Outbound channels | Email via Resend for documents only (`composer.json:25`, `config/mail.php:64-65`). **No SMS, no WhatsApp** anywhere (zero hits for twilio/whatsapp/vonage in app/, config/, composer.json). Appointment reminders are a stub that marks rows `sent` (`Scheduling/Infrastructure/Jobs/DispatchAppointmentReminder.php:22-26`). |
| "CRM-lite" | Loyalty (`loyalty_programs`, `loyalty_members` with `customer_id→partners` + its own `phone/email/first_name/last_name/date_of_birth`, `loyalty_transactions`), Promotions (`promotions`, `promotion_usages.partner_id`), coupons, vouchers. |
| Consent / opt-in / channel prefs / locale | **Absent on both `partners` and `contacts`** — no `marketing_opt_in`, `consent`, `preferred_channel`, `language`/`locale`, `tags`, `source`, `phone_normalized`, `email_normalized`. |
| Contact points | `partners.email`, `partners.phone(50)`; `contacts.email/phone/mobile`. Only normalizer is Loyalty's digit-strip (`Loyalty/Domain/Entities/LoyaltyMember.php:112-117`) — **not E.164**. No libphonenumber. |
| Events a CRM could subscribe to | `PartnerCreated/Updated/Deleted` only (`Partner/Domain/Events/`), dispatched from the **controller** (`PartnerController.php:230,310,382`), so service/import/POS-created partners emit nothing. **No Contact events.** `Event::listen` is the house subscription pattern (`EventServiceProvider.php:108-109`). |
| Person ↔ organization link | `party_contacts` pivot (`party_id, contact_id, job_title, department, is_primary, is_invoice_contact, is_delivery_contact`, unique pair) — stays. |

## 2. Selling to companies AND private persons — does it work today?

- **Back office:** `CreateDocumentRequest.php:66-70` requires only a `partner_id` scoped to tenant+company; there is **no person/company branch** anywhere in the document path. A facture/quote/order to a private person works today; to a company too. ✅
- **POS:** attaching a customer works in the app (`paymentStore.ts:200-221,273,400`), the server accepts `customer_id`/`contact_id` (`StoreReceiptRequest.php:97-104`), and `pos_receipts` has `partner_id/contact_id/customer_name/customer_identifier`. **But the device seals `buyer: null` on every SALE_RECEIPT** (`SaleReceiptPayload.ts:152`, `SaleReceiptV5Payload.ts:133`) — so the attached customer is dropped from the fiscal sale and from customer history. **H1-a1 is the load-bearing fix for "sell to a private person at the till"**; it is in the Phase 1 handover. ⚠️→✅ after H1-a1.
- Private-person facture with no matricule: allowed today and stays allowed under OQ3 (person exempt). ✅

## 3. Why the target model is CRM-safe

1. **One party table with a nature axis is exactly what marketing CRMs target.** Odoo's mass-mailing / marketing-automation attaches to `res.partner` (person or company, `is_company`); Dolibarr's emailing targets *tiers* + *contacts*; HubSpot/Brevo model "contacts" (people) with an optional "company". Our target: `partners(party_kind=person)` = the B2C audience, `contacts` (people at an organization, via `party_contacts`) = the B2B audience. Both remain addressable by id; a marketing audience is a **read model that unions the two** — no schema change needed later.
2. **Contacts are never billable, but they are never deleted either** (OQ2 keeps the table + API and wires them into the org detail). A CRM that needs "the buyer at Société X who reads WhatsApp" has the row.
3. **WhatsApp identity = E.164 phone.** The spec's shared `ContactPointNormalizer` (Phase 2/4, §5.4) is the prerequisite for WhatsApp/SMS deliverability and for dedup — it is already on the plan; the CRM inherits it.
4. **Facebook/Messenger identity is NOT a phone** (page-scoped PSID) — it needs a channel-identity table, not a column. Nothing in the party model prevents `party_channel_identities(party_id|contact_id, channel, external_id, verified_at)` later; it is a pure add.
5. **Consent is legally separate from identity** (TN loi 2004-63; GDPR for FR) and per channel — it belongs in its own table with source + timestamp, never as booleans on `partners`. Again a pure add.

## 4. Reservations to fold into Phase 2 (cheap now, expensive later)

| # | Reservation | Why now |
|---|---|---|
| R-A | Emit `PartnerCreatedV2/UpdatedV2` (needed anyway for `party_kind`, rule 8) **from `PartnerService`**, not the controller, so import-, POS- and seeder-created parties emit too; add `ContactCreated/Updated/Deleted`. | The CRM's sync/segmentation hooks are events; today 3 of 5 write paths are silent. Moving the dispatch point is trivial while the V2 events are being written. |
| R-B | `partners.preferred_locale` (nullable, ISO `fr-TN` / `ar-TN` / `fr-FR`), shown on the party form. | TN customers need FR/AR messaging; adding it with the `party_kind` migration costs one column; adding it later costs a separate migration + backfill. Not a marketing field per se — also drives document language. |
| R-C | Normalized contact points (`phone_normalized`, `email_normalized`, E.164 with company country as default region) — already in spec §8.1. | WhatsApp/SMS addressability and dedup. |
| R-D | Loyalty's private identity columns (`loyalty_members.phone/email/first_name/last_name/date_of_birth`) stay, but Phase 4 dedup treats `loyalty_members.customer_id` as the canonical link and stops minting a second phone normalizer. | Two normalizers = two identities for one person = the CRM double-sends. |

## 5. Explicitly NOT built now (and the rule that keeps the door open)

- No consent table, no channel-identity table, no tags/segments, no campaign/message tables, no WhatsApp/SMS transport, no "lead/prospect" entity.
- **Standing design rule for the future CRM (proposed, owner to ack):** (a) **never a third person table** — a lead/prospect is a `partners` row with `party_kind` and a lifecycle status, or a `leads` table that *converts into* a partner, never a parallel identity; (b) **audiences are read models** over `partners(person)` ∪ `contacts`; (c) **all outbound customer communication goes through the existing `Communication` module** behind a consent check — never `Mail::to()` from a feature module.

## 6. Sources
- Census: Explore agent, 2026-08-29, read-only over `23b1b8a65` (citations inline above).
- Spec: `docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` §2 (Odoo/Dolibarr/ERPNext/Square models), §5.4, §8.1.
