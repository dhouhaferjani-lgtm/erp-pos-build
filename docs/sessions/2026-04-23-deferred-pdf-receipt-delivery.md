# Deferred: Customer Receipt PDF Delivery (email + WhatsApp)

**Status:** deferred (not on critical path for Otospex go-live).
**Owner:** TBD.
**Related bug batch:** Cluster B — BG1 (PDF print button hidden on 2026-04-23).

## Context

The PDF print button was removed from `CheckoutSuccessModal.tsx` in
Cluster B (2026-04-23). The current backend route
`GET /api/v1/pos/receipts/{id}/pdf` remains intact and is still used by
`TodaySalesPanel.tsx` (reprint from "Today's Sales"). The checkout
success flow now only exposes the ESC/POS thermal print action.

## Desired Future Flow

On a successful sale (after the fiscal receipt is stamped), the cashier
should be able to deliver the receipt to the customer through:

1. **Thermal print** (already works today via ESC/POS).
2. **Email** — input field for customer email; backend enqueues a job
   that renders the PDF and sends via Resend (already wired up in the
   Laravel app). Subject/body localized via the tenant's `locale`.
3. **WhatsApp** — pluggable provider interface. First integration
   target TBD (Twilio, Meta Cloud API, or a local Tunisian gateway).
   Requires phone-number input + opt-in checkbox.

## Proposed Architecture

- **PDF generation:** move to the Rust side of the Tauri shell using
  the `printpdf` crate. The renderer consumes the same `ReceiptData`
  shape that `printReceipt()` currently uses for ESC/POS, avoiding a
  round-trip to the server when the POS is offline. The backend
  `/api/v1/pos/receipts/{id}/pdf` endpoint stays as the "authoritative"
  PDF for re-prints from TodaySalesPanel and admin tooling.
- **Delivery channels:** each channel is a domain service behind a
  `ReceiptDeliveryChannel` interface (Shared/Contracts). The POS fires
  a `ReceiptDeliveryRequested` event with channel + recipient; the
  backend subscribes and enqueues the actual send.
- **UI:** a post-sale "Send receipt" drawer in the `CheckoutSuccessModal`
  with tabs for Email / WhatsApp and a free-text "no delivery" escape.

## Scope NOT in this deferred plan

- Offline queueing of email/WhatsApp sends (POS → backend on reconnect).
- Customer-facing preferences UI (remembering email per customer).
- Localized PDF templates (font + logo handling).

## When to schedule

After:
- Otospex go-live is green.
- Workshop module work order invoicing path is stable.
- A product-owner decision on the WhatsApp provider.
