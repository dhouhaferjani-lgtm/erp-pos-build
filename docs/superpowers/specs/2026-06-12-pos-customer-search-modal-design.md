# POS Customer Search → Cart-Header Button + Fixed-Size Modal — Design

**Date:** 2026-06-12
**Status:** Approved design, revised after Codex adversarial review (pending user review of rev 2)
**Scope:** Item 2 of the parapharmacy/POS stabilization pass
**App:** `apps/pos` (React POS)
**Branch base:** `dev`
**Review:** `docs/superpowers/specs/reviews/2026-06-12-tax-pos-specs-codex-review.md` (Spec 2 = APPROVE-WITH-EDITS; edits folded in below)

### rev 2 changes (from Codex review)
- **m3:** base `Modal` `size` is `max-w-*` + `max-h-*` (not true fixed dims) — `CustomerSearchModal` must reserve a **stable content height** across search/create/selected states so it never resizes on interaction.
- **m4:** define the **account-payment modal lifecycle** (close customer modal on success; `closable={!isProcessing}` while the request is in flight).
- **n2:** `selectedCustomer` also drives account-charge eligibility in `AdvancedPaymentsModal` — it **stays in `paymentStore`**, never moves to modal-local state.
- **n3:** the extracted body has hardcoded English strings — migrate to `t()` during the move.

---

## 1. Problem

The customer search (Name/Phone/Email fields + "Create local customer") was placed **inline above the cart**
on the POS HomePage via `CustomerAttachPanel`, pushing the cart down and cluttering the primary checkout
surface. For a POS, the cart must stay put; customer lookup is an occasional action that belongs behind a
button/modal.

Current layout (`apps/pos/src/pages/HomePage.tsx:1343-1379`): the left cart panel mounts
`CustomerAttachPanel` (lines 1344-1349) **above** `TransactionCart` (lines 1350-1378). There is **no**
attached-customer chip on the cart itself today — the selected customer only renders inside the panel.

---

## 2. Goal

- Customer lookup lives behind a **customer icon/button in the cart panel header** (owner decision: cart panel,
  not the global app header — keeps customer context next to the cart it scopes).
- Clicking it opens a **fixed-size modal** (per the project "modals never resize on interaction" convention)
  containing the existing search + create-local-customer + account-payment UI — moved, not rewritten.
- When a customer is attached, the **cart header shows a chip** (name + detach ✕); the trigger button
  collapses into / sits beside the chip.
- The cart returns to the top of the panel; no vertical displacement.

---

## 3. Current-state map (from exploration)

- **HomePage** `apps/pos/src/pages/HomePage.tsx` — left panel hosts `CustomerAttachPanel` then `TransactionCart`.
- **`CustomerAttachPanel`** `apps/pos/src/components/customers/CustomerAttachPanel.tsx` — two modes:
  (a) selected-customer (name/contact, `CustomerBalanceBadge`, account-payment "Record" input, detach ✕);
  (b) search/create (`CustomerSearchInput` + Name/Phone/Email + "Create local customer").
- **`CustomerSearchInput`** `apps/pos/src/components/customers/CustomerSearchInput.tsx` — debounced search over
  the local SQLite `customerRepository.searchCustomers`, scoped by tenant_id + company_id.
- **Store** `apps/pos/src/stores/paymentStore.ts` — `selectedCustomer` + `attachCustomer()` / `detachCustomer()`.
  Single source of truth. **No store changes needed.**
- **Modal** `apps/pos/src/components/pos/Modal.tsx` — reusable, fixed `size` (`sm|md|lg|xl|full`), focus trap,
  Escape + backdrop close, internal scroll. `md` matches `CashTenderedModal`/`VariantPickerModal`.
- **Create-local-customer flow** — `enqueuePendingCustomer()` + `deterministicPendingCustomerUuid()` +
  immediate `attachCustomer()`. Unchanged.
- **Tests** — `CustomerSearchInput.test.tsx`, `CustomerAttachPanel.test.tsx`, `CustomerBalanceBadge.test.tsx`,
  `__tests__/AccountChargeConfirmation.test.tsx`. No HomePage-level customer-attach test yet.

---

## 4. Design

### 4.1 Components

- **`CustomerSearchModal.tsx` (new)** — wraps the existing search + create + account-payment UI inside `Modal`
  (`size="md"`). Props mirror what `CustomerAttachPanel` needs: `tenantId`, `companyId`, `terminalId`,
  `staleThresholdMinutes`, `onAccountPaymentComplete`, `isOpen`, `onClose`. Selecting/creating a customer
  attaches it via the store and closes the modal.
  - **Stable height (m3):** the base `Modal` only fixes `max-w`/`max-h` (`Modal.tsx:56-63`), so the content
    shell must reserve a **fixed min-height** wide enough for the tallest of the three states
    (search / create-local / selected-with-balance+record) so the modal does not resize when toggling between
    them. Body scrolls internally.
  - **Account-payment lifecycle (m4):** while `processAccountPayment` is in flight, pass `closable={false}`
    (disables Escape/backdrop, `Modal.tsx:31-39`); on success, **close `CustomerSearchModal` first**, then let
    the existing `onAccountPaymentComplete` open the success modal — never stack success on top of an open
    customer modal.
- **`CartCustomerControl.tsx` (new, small)** — rendered in the cart panel header. Subscribes to
  `paymentStore.selectedCustomer`:
  - no customer → a `User`/`UserPlus` icon button ("Customer") that opens the modal.
  - customer attached → an **`AttachedCustomerChip`** (name, optional balance hint, detach ✕ → `detachCustomer()`),
    plus a small "change" affordance that reopens the modal.
- **`CustomerAttachPanel`** — its body is refactored into the modal. The panel component is either retired or
  reduced to the modal's content; account-payment "Record" logic moves verbatim into the modal's
  selected-customer state. No logic change to attach/create/record.

### 4.2 HomePage / cart wiring

- Remove `CustomerAttachPanel` from `HomePage.tsx:1344-1349`.
- Render `CartCustomerControl` in the cart panel header (top of `TransactionCart`, or the panel header row that
  owns it). Modal open/close state lives in HomePage (or the cart container), passed to `CustomerSearchModal`.
- Cart returns to the top of the panel.

### 4.3 Conventions

- Fixed-size modal (`feedback_modal_fixed_size`); `size="md"`.
- All strings via `t()` (new keys under the POS customer namespace).
- Tailwind colours via design tokens (`@/lib/designTokens`) — no hardcoded colour classes in new files.
- lucide-react icons (`User`, `UserPlus`, `X`), matching existing header/toolbar usage.
- No `paymentStore` shape change; `selectedCustomer` remains the single source of truth — it is read not only
  for the chip but also by `AdvancedPaymentsModal.tsx:161-167` for account-charge eligibility (n2). Attachment
  must **never** move to HomePage/modal-local state.

---

## 5. Scope boundaries

**In:** move search/create/account-payment into a fixed-size modal; cart-header trigger; attached-customer chip
with detach; HomePage relayout; tests.

**Out:** any change to customer search logic, the SQLite repositories, the create-local-customer/pending-sync
flow, the account-payment ("Record") business logic, or `paymentStore` shape. No server-side customer changes.
No global-header trigger (owner chose cart panel).

---

## 6. Testing (TDD, Vitest — scoped)

1. Cart header renders the **Customer trigger** when `selectedCustomer` is null.
2. Clicking the trigger **opens** `CustomerSearchModal`.
3. Selecting a searched customer **attaches** it (store), **closes** the modal, and renders the **chip**.
4. "Create local customer" enqueues + attaches + closes + shows the chip (reuse existing flow assertions).
5. Chip **detach** clears `selectedCustomer`.
6. Modal **does not resize** when switching search ↔ create ↔ selected states (assert stable container height);
   Escape/backdrop close when idle; `closable=false` during async Record (Escape/backdrop no-op).
7. Account-payment success **closes the customer modal before** the success modal appears (no stacked modals).
8. Existing `CustomerSearchInput` / account-payment tests migrate to render through the modal, querying
   localized (`t()`) labels after the n3 string migration.

Run scoped: `pnpm test --run CustomerSearchModal CartCustomerControl` (+ migrated specs). `pnpm typecheck`,
`pnpm lint` before done.

---

## 7. Risks & notes

- **Account-payment ("Record") inside a modal** — ensure the modal stays `closable={false}` while the
  account-payment request is in flight so it can't be dismissed mid-operation; restore on completion/failure.
- **Focus management** — `Modal` already provides focus trap + restore; verify the search input autofocuses on open.
- **POS offline-first** — search/create are local-SQLite; no online dependency introduced. Behaviour unchanged.
- **Independent of Item 1** — frontend-only, separate branch/PR; no shared state with the tax work.
