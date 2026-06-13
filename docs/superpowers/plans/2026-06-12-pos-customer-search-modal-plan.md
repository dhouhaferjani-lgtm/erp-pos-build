# POS Customer Search → Cart-Header Button + Fixed-Size Modal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move POS customer search/create/account-payment off the cart surface into a fixed-size modal opened from a cart-header button, with an attached-customer chip on the cart header.

**Architecture:** A new `CustomerSearchModal` wraps the existing (moved, not rewritten) search + create + account-payment UI inside the POS `Modal` with a stable content height. A small `CartCustomerControl` in the cart header subscribes to `paymentStore.selectedCustomer` and renders either the trigger button or the attached chip. `paymentStore` is unchanged — it stays the single source of truth (also read by `AdvancedPaymentsModal`).

**Tech Stack:** React 19 / TypeScript strict / Zustand 5 / Vitest + Testing Library / Tailwind 4 design tokens / lucide-react / react-i18next.

**Spec:** `docs/superpowers/specs/2026-06-12-pos-customer-search-modal-design.md` (rev 2)
**Review addressed:** `docs/superpowers/specs/reviews/2026-06-12-tax-pos-specs-codex-review.md` (Spec 2 = APPROVE-WITH-EDITS)

**Conventions:** no `any` (use `unknown` + guards); all visible text via `t()` (new POS customer namespace keys); Tailwind colors via `@/lib/designTokens`; modals fixed-size (never resize on interaction); `pnpm typecheck` + `pnpm lint` before each commit; scoped Vitest (`pnpm test --run <pattern>`).

---

## File Structure

**New:**
- `apps/pos/src/components/customers/CustomerSearchModal.tsx` — modal shell around the moved body; stable height; lifecycle.
- `apps/pos/src/components/customers/CartCustomerControl.tsx` — cart-header trigger button OR attached-customer chip.
- Tests: `CustomerSearchModal.test.tsx`, `CartCustomerControl.test.tsx`, and `HomePage` integration additions.

**Modified:**
- `apps/pos/src/pages/HomePage.tsx:1344-1349,34` — remove inline `CustomerAttachPanel`, mount `CartCustomerControl` in cart header + `CustomerSearchModal`; hold open/close state.
- `apps/pos/src/components/customers/CustomerAttachPanel.tsx` — body extracted into the modal; strings → `t()`. (Retire the standalone panel once the modal owns its content; it has no other non-test consumer per review n2.)

---

### Task 1: `CartCustomerControl` — trigger vs chip

**Files:**
- Create: `apps/pos/src/components/customers/CartCustomerControl.tsx`
- Test: `apps/pos/src/components/customers/CartCustomerControl.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { CartCustomerControl } from './CartCustomerControl';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }));

const mockState: { selectedCustomer: unknown } = { selectedCustomer: null };
vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: (sel: (s: typeof mockState & { detachCustomer: () => void }) => unknown) =>
    sel({ ...mockState, detachCustomer: vi.fn() }),
}));

describe('CartCustomerControl', () => {
  it('renders the trigger button when no customer is attached', () => {
    mockState.selectedCustomer = null;
    const onOpen = vi.fn();
    render(<CartCustomerControl onOpen={onOpen} />);
    const btn = screen.getByRole('button', { name: /customer\.attach/i });
    fireEvent.click(btn);
    expect(onOpen).toHaveBeenCalledOnce();
  });

  it('renders the attached-customer chip with the name when attached', () => {
    mockState.selectedCustomer = { id: 'c1', name: 'Amine Trabelsi' };
    render(<CartCustomerControl onOpen={vi.fn()} />);
    expect(screen.getByText('Amine Trabelsi')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /customer\.detach/i })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test --run CartCustomerControl`
Expected: FAIL (component does not exist).

- [ ] **Step 3: Write minimal implementation**

```tsx
import { useTranslation } from 'react-i18next';
import { User, X } from 'lucide-react';
import { usePaymentStore } from '@/stores/paymentStore';
import { tokens, textColors } from '@/lib/designTokens';

interface CartCustomerControlProps {
  onOpen: () => void;
}

export function CartCustomerControl({ onOpen }: CartCustomerControlProps) {
  const { t } = useTranslation();
  const selectedCustomer = usePaymentStore((s) => s.selectedCustomer);
  const detachCustomer = usePaymentStore((s) => s.detachCustomer);

  if (selectedCustomer) {
    return (
      <div className={`flex items-center gap-2 rounded-md px-2 py-1 ${tokens.surfaceMuted}`}>
        <User className="h-4 w-4" aria-hidden />
        <button type="button" onClick={onOpen} className={`text-sm font-medium ${textColors.primary}`}>
          {selectedCustomer.name}
        </button>
        <button
          type="button"
          onClick={detachCustomer}
          aria-label={t('customer.detach')}
          className={textColors.muted}
        >
          <X className="h-4 w-4" />
        </button>
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-label={t('customer.attach')}
      className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${textColors.secondary}`}
    >
      <User className="h-4 w-4" />
      {t('customer.attach')}
    </button>
  );
}
```

> Use the actual token names from `@/lib/designTokens` — open the file and substitute the closest existing tokens for surface/muted/primary/secondary text. Do not invent token names.

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test --run CartCustomerControl`
Expected: PASS. Then `pnpm typecheck && pnpm lint`.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/customers/CartCustomerControl.tsx apps/pos/src/components/customers/CartCustomerControl.test.tsx
git commit -m "feat(pos): cart-header customer control (trigger + attached chip)"
```

---

### Task 2: Add i18n keys for the customer control/modal

**Files:**
- Modify: the POS customer i18n namespace JSON (locate via `apps/pos/src/**/locales` or the namespace used by existing customer strings).
- Test: none (covered indirectly).

- [ ] **Step 1:** Add keys `customer.attach` ("Customer"), `customer.detach` ("Remove customer"), `customer.modalTitle` ("Customer"), and keys for the moved body strings (`Attached`, `Amount`, `Record`, `Create local customer`, search placeholder) in the EN locale and the FR/AR locales (translate). Follow the 3-place i18n setup if a new namespace is needed (import, resources, ns array) per CLAUDE.md.
- [ ] **Step 2–4:** `pnpm typecheck` (catches missing-key types if the project types its resources).
- [ ] **Step 5:** Commit `git commit -m "i18n(pos): customer control + modal strings (en/fr/ar)"`

---

### Task 3: `CustomerSearchModal` — moved body, stable height, lifecycle

**Files:**
- Create: `apps/pos/src/components/customers/CustomerSearchModal.tsx`
- Modify: `apps/pos/src/components/customers/CustomerAttachPanel.tsx` (extract body; migrate strings to `t()`)
- Test: `apps/pos/src/components/customers/CustomerSearchModal.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { CustomerSearchModal } from './CustomerSearchModal';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }));
// Mock the SQLite-backed search + the store actions used inside the body.
vi.mock('@/stores/paymentStore', () => {
  const attachCustomer = vi.fn();
  return {
    usePaymentStore: (sel: (s: { selectedCustomer: unknown; attachCustomer: typeof attachCustomer; detachCustomer: () => void }) => unknown) =>
      sel({ selectedCustomer: null, attachCustomer, detachCustomer: vi.fn() }),
    __attachCustomer: attachCustomer,
  };
});

describe('CustomerSearchModal', () => {
  it('does not render content when closed', () => {
    render(<CustomerSearchModal isOpen={false} onClose={vi.fn()} tenantId="t" companyId="c" terminalId="term" />);
    expect(screen.queryByText('customer.modalTitle')).not.toBeInTheDocument();
  });

  it('renders the search/create body when open inside a fixed-height shell', () => {
    render(<CustomerSearchModal isOpen onClose={vi.fn()} tenantId="t" companyId="c" terminalId="term" />);
    expect(screen.getByText('customer.modalTitle')).toBeInTheDocument();
    // stable height shell present
    expect(screen.getByTestId('customer-modal-shell')).toHaveClass('min-h-[420px]');
  });

  it('calls onClose after a customer is selected', () => {
    const onClose = vi.fn();
    render(<CustomerSearchModal isOpen onClose={onClose} tenantId="t" companyId="c" terminalId="term" />);
    // Simulate the inner search selecting a customer via the exposed onSelect path:
    fireEvent.click(screen.getByTestId('test-select-customer'));
    expect(onClose).toHaveBeenCalled();
  });
});
```

> The third assertion requires the modal to close when a customer is attached. Implement by passing an `onSelected` callback into the moved body that does `attachCustomer(c); onClose();`. The `test-select-customer` hook is a test-only affordance — instead, prefer asserting via the real `CustomerSearchInput` mock's `onSelect`. Adjust to the existing `CustomerSearchInput` test pattern in `CustomerSearchInput.test.tsx`.

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test --run CustomerSearchModal`
Expected: FAIL (component does not exist).

- [ ] **Step 3: Write minimal implementation**

```tsx
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/pos/Modal';
import { CustomerAttachBody } from './CustomerAttachPanel'; // extracted body export

interface CustomerSearchModalProps {
  isOpen: boolean;
  onClose: () => void;
  tenantId: string;
  companyId: string;
  terminalId: string;
  staleThresholdMinutes?: number;
  onAccountPaymentComplete?: () => void;
}

export function CustomerSearchModal({
  isOpen,
  onClose,
  tenantId,
  companyId,
  terminalId,
  staleThresholdMinutes = 30,
  onAccountPaymentComplete,
}: CustomerSearchModalProps) {
  const { t } = useTranslation();
  const [isProcessing, setIsProcessing] = useState(false);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('customer.modalTitle')}
      size="md"
      closable={!isProcessing}
    >
      {/* Stable-height shell so search/create/selected states do not resize the modal (m3) */}
      <div data-testid="customer-modal-shell" className="min-h-[420px]">
        <CustomerAttachBody
          tenantId={tenantId}
          companyId={companyId}
          terminalId={terminalId}
          staleThresholdMinutes={staleThresholdMinutes}
          onProcessingChange={setIsProcessing}
          onSelected={onClose}
          onAccountPaymentComplete={() => {
            onClose(); // close customer modal BEFORE success modal opens (m4)
            onAccountPaymentComplete?.();
          }}
        />
      </div>
    </Modal>
  );
}
```

And in `CustomerAttachPanel.tsx`: extract the existing two-mode body into an exported `CustomerAttachBody` component that accepts the new props (`onProcessingChange`, `onSelected`), wiring:
- after `attachCustomer(...)` on search-select and on create-local-customer, call `onSelected?.()`.
- wrap `processAccountPayment` with `onProcessingChange(true)` / `onProcessingChange(false)` around the await.
- migrate the hardcoded strings (`Customer`, `Attached`, `Amount`, `Record`, `Create local customer`, etc.) to `t()` keys from Task 2.

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test --run CustomerSearchModal`
Expected: PASS. `pnpm typecheck && pnpm lint`.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/customers/CustomerSearchModal.tsx apps/pos/src/components/customers/CustomerAttachPanel.tsx apps/pos/src/components/customers/CustomerSearchModal.test.tsx
git commit -m "feat(pos): customer search modal with stable height + account-payment lifecycle"
```

---

### Task 4: Wire into HomePage cart header; remove inline panel

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx:34,1344-1349`
- Test: `apps/pos/src/pages/__tests__/HomePage.customerModal.test.tsx`

- [ ] **Step 1: Write the failing test** — render `HomePage` (reuse the mocking setup from existing `HomePage.*.test.ts` files), assert (a) cart header shows the customer trigger, (b) clicking it opens the modal, (c) no `CustomerAttachPanel` is rendered inline above the cart. Expected FAIL.

- [ ] **Step 2: Run** `pnpm test --run HomePage.customerModal` → FAIL.

- [ ] **Step 3: Implement** — remove the `CustomerAttachPanel` import (line 34) and its inline mount (1344-1349). Add local state `const [showCustomerModal, setShowCustomerModal] = useState(false);`. In the cart header (top of the cart panel / `TransactionCart` header), render `<CartCustomerControl onOpen={() => setShowCustomerModal(true)} />`. Near the other HomePage modals, render `<CustomerSearchModal isOpen={showCustomerModal} onClose={() => setShowCustomerModal(false)} tenantId={...} companyId={...} terminalId={...} onAccountPaymentComplete={() => setShowSuccessModal(true)} />` using the same id sources the panel used.

- [ ] **Step 4: Run** `pnpm test --run HomePage.customerModal` → PASS. Run the existing HomePage tests (`pnpm test --run HomePage`) to confirm no regression. `pnpm typecheck && pnpm lint`.

- [ ] **Step 5: Commit** `git commit -m "feat(pos): move customer search into cart-header modal; cart returns to top"`

---

### Task 5: Manual smoke (Tauri) — checklist

**Files:** none (manual).

- [ ] Launch the POS; confirm the cart sits at the top of the panel (no inline customer block).
- [ ] Click the customer button → modal opens at a fixed size; switching search ↔ create ↔ selected does **not** resize it.
- [ ] Search + select a customer → modal closes, chip shows on the cart header; detach clears it.
- [ ] Create a local customer offline → attaches + chip shows; pending-sync unaffected.
- [ ] Record an account payment in the modal → modal is non-dismissible while processing, then closes and the success modal appears (no stacked modals).
- [ ] Account-charge eligibility in `AdvancedPaymentsModal` still sees the attached customer.

---

## Self-review checklist (completed)

- **Spec coverage:** §4.1 modal→Task 3; stable height (m3)→Task 3; lifecycle (m4)→Task 3; §4.1 chip/trigger→Task 1; §4.2 HomePage rewire→Task 4; §4.3 i18n→Task 2; store-as-truth (n2)→unchanged by design, asserted in Task 1/4; §6 tests→per task + Task 5 smoke.
- **Placeholder scan:** token names + i18n namespace + the `CustomerSearchInput` onSelect test pattern are flagged to be matched against the real files rather than invented.
- **Type consistency:** `CustomerSearchModal` props and the extracted `CustomerAttachBody` props (`onProcessingChange`, `onSelected`, `onAccountPaymentComplete`) are used consistently across Tasks 3–4; `CartCustomerControl` `onOpen` consistent across Tasks 1, 4.
