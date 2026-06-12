# POS Customer-Search Modal — Manual Smoke Checklist

**Date:** 2026-06-12
**Branch:** `feat/pos-customer-search-modal`
**Scope:** Item 2 of the parapharmacy/POS stabilization pass — customer search moved off the cart surface into a cart-header control + fixed-size modal.
**Spec/plan:** `docs/superpowers/specs/2026-06-12-pos-customer-search-modal-design.md`, `docs/superpowers/plans/2026-06-12-pos-customer-search-modal-plan.md`

Automated coverage (Vitest) verifies component logic in isolation. POS offline flows (sale, PIN, sync, account payment) are **Tauri-only** and cannot be exercised in the browser/Vitest harness — so the following must be confirmed manually on a real POS terminal build before merge to `dev`.

## Preconditions
- A claimed POS terminal seeded with at least a few customers (synced) and an offline-creatable customer scenario.
- A tenant/company/terminal context bound (the cart-header control + modal only render when `tenantId`, `companyId`, `terminalId` are all present).

## Checklist

- [ ] **Cart at top.** On the POS home, the cart (`TransactionCart`) sits at the top of the left panel. There is NO large inline customer block above it. A single compact customer control sits in the cart-panel header.
- [ ] **Trigger (no customer).** With no customer attached, the cart header shows a "Customer" trigger button (person icon). Clicking it opens the customer modal.
- [ ] **Fixed size / no resize.** The modal opens at a fixed size. Switching between its states — search → "create local customer" form → selected-customer (with balance + Record) — does NOT resize the modal (stable min-height; body scrolls internally if needed).
- [ ] **Search + attach.** Search a customer by name/phone/email, select a result → the modal CLOSES and the cart header shows the attached-customer **chip** (name).
- [ ] **Detach.** Click the chip's ✕ (detach) → the customer is removed; the header returns to the trigger button. Cart contents unaffected.
- [ ] **Re-open while attached.** With a customer attached, clicking the chip (name) re-opens the modal showing the selected-customer state (balance badge, account-payment input, detach).
- [ ] **Create local customer (offline).** With connectivity off, open the modal, fill Name/Phone/Email, "Create local customer" → customer is created locally, attached (chip shows), modal closes; the pending-sync queue contains the new customer (verify it syncs when back online).
- [ ] **Account payment lifecycle.** With a customer attached that has an account, enter an amount and press "Record":
  - [ ] While the request is in flight, the modal is **non-dismissible** (Escape and backdrop click do nothing).
  - [ ] On success, the customer modal **closes first**, then the success modal appears (no two modals stacked).
  - [ ] On failure, the modal becomes dismissible again and shows the error; no stuck "processing" state.
- [ ] **AdvancedPaymentsModal eligibility.** Open Advanced Payments with a customer attached → account-charge eligibility still reflects the attached customer (it reads the same `paymentStore.selectedCustomer`).
- [ ] **i18n.** Switch POS language EN ↔ FR → all customer control/modal labels (Customer, Remove customer, Attached, Amount, Record, Create local customer, search placeholder) are translated. (Arabic is not configured in this POS.)
- [ ] **Complete a sale with the attached customer** → the receipt/transaction carries the customer as before (no regression from the relayout).

## Notes
- `paymentStore.selectedCustomer` remains the single source of truth (chip + AdvancedPaymentsModal + checkout all read it) — no attach state was moved into local/modal state.
- The "Creating…" button-loading label is currently the one un-i18n'd ephemeral string (tracked as a minor follow-up; not user-blocking).
