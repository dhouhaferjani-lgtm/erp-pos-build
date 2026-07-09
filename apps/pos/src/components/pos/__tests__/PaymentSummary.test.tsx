/**
 * T1.2 Step 2.3 — paymentConfigReady gate on PaymentSummary.
 *
 * BEFORE this fix: the cash button was enabled whenever the parent
 * `disabled` prop was false. A cashier opening shift before T0.5's
 * scheduler tick had refreshed `paymentStore` from SQLite — or before
 * the first `fetchPaymentConfig` resolved — could press Cash and hit
 * `paymentRepositories.find(r => r.kind === 'cash')` returning
 * undefined (the recurring "no cash method" symptom that prompted
 * Phase 2 in the first place).
 *
 * AFTER: PaymentSummary derives `paymentConfigReady = paymentMethods.length > 0
 * && paymentRepositories.length > 0` and disables both the cash and
 * advanced-payments buttons (with `aria-disabled="true"` and a tooltip)
 * when not ready. The existing per-method validation in
 * processCashCheckout/processAdvancedCheckout remains as a
 * defense-in-depth backstop.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (n: number) => `$${n.toFixed(2)}`,
  }),
}));

import { PaymentSummary } from '../PaymentSummary';

const baseProps = {
  subtotal: 10,
  taxAmount: 1,
  discountAmount: 0,
  total: 11,
  hasDiscount: false,
  onPayCash: vi.fn(),
  onAdvancedPayments: vi.fn(),
};

describe('PaymentSummary — T1.2 Step 2.3 paymentConfigReady gate', () => {
  it('T1.2: cash button is disabled when paymentRepositories is empty (paymentConfigReady=false)', () => {
    const onPayCash = vi.fn();
    render(
      <PaymentSummary
        {...baseProps}
        onPayCash={onPayCash}
        paymentMethods={[makePaymentMethod({ id: 'pm-cash' })]}
        paymentRepositories={[]}
      />,
    );

    const cashButton = screen.getByRole('button', { name: /cashPayment/i });
    expect(cashButton).toBeDisabled();
    expect(cashButton).toHaveAttribute('aria-disabled', 'true');

    fireEvent.click(cashButton);
    expect(onPayCash).not.toHaveBeenCalled();
  });

  it('T1.2: cash button is disabled when paymentMethods is empty (paymentConfigReady=false)', () => {
    const onPayCash = vi.fn();
    render(
      <PaymentSummary
        {...baseProps}
        onPayCash={onPayCash}
        paymentMethods={[]}
        paymentRepositories={[makePaymentRepository({ id: 'repo-cash' })]}
      />,
    );

    const cashButton = screen.getByRole('button', { name: /cashPayment/i });
    expect(cashButton).toBeDisabled();
    expect(cashButton).toHaveAttribute('aria-disabled', 'true');

    fireEvent.click(cashButton);
    expect(onPayCash).not.toHaveBeenCalled();
  });

  it('T1.2: cash button title shows the configNotLoaded translation key when gated', () => {
    render(
      <PaymentSummary
        {...baseProps}
        paymentMethods={[]}
        paymentRepositories={[]}
      />,
    );

    const cashButton = screen.getByRole('button', { name: /cashPayment/i });
    // Mocked t() returns the key verbatim; the production component MUST
    // pass the configNotLoaded key into the title attribute. The i18n
    // smoke test (recoveryScreenI18n-style) is the separate guard that
    // the key actually resolves to translated text in en + fr.
    expect(cashButton).toHaveAttribute('title', 'pos:payment.configNotLoaded');
  });

  it('T1.2: cash button is enabled when both paymentMethods and paymentRepositories are populated', () => {
    const onPayCash = vi.fn();
    render(
      <PaymentSummary
        {...baseProps}
        onPayCash={onPayCash}
        paymentMethods={[
          makePaymentMethod({ id: 'pm-cash', is_active: true }),
          makePaymentMethod({ id: 'pm-card', is_active: true }),
        ]}
        paymentRepositories={[makePaymentRepository({ id: 'repo-cash' })]}
      />,
    );

    const cashButton = screen.getByRole('button', { name: /cashPayment/i });
    expect(cashButton).not.toBeDisabled();
    // Aria-disabled is either absent or "false" when enabled — assert
    // the button is interactive.
    expect(cashButton.getAttribute('aria-disabled')).not.toBe('true');

    fireEvent.click(cashButton);
    expect(onPayCash).toHaveBeenCalledTimes(1);
  });

  it('T1.2: advanced-payments button is hidden when gated (even with hasMultipleMethods true)', () => {
    render(
      <PaymentSummary
        {...baseProps}
        paymentMethods={[
          makePaymentMethod({ id: 'pm-1', is_active: true }),
          makePaymentMethod({ id: 'pm-2', is_active: true }),
        ]}
        paymentRepositories={[]}
      />,
    );

    // Advanced-payments button must not be rendered when payment config
    // is not ready — even though the multi-method threshold is met.
    expect(
      screen.queryByRole('button', { name: /advancedPayments/i }),
    ).not.toBeInTheDocument();
  });
});

/**
 * Caisse visual redesign — payment footer action row.
 *
 * Owner-reported on real Tauri: the two footer buttons rendered roughly
 * equal-width, and "Autres paiements" CLIPPED to "Autres paieme…" at real
 * cart width (~300-360px). New contract:
 *  - Cash stays primary and LARGE (owns the row; full label + icon).
 *  - "Autres paiements" becomes a compact icon-only control whose
 *    accessible name comes from t() via aria-label + title — it has no
 *    visible text label, so it can never clip.
 *  - All behavior preserved: onClick handlers, disabled gating.
 */
describe('PaymentSummary — compact other-payments control (caisse redesign)', () => {
  const readyProps = {
    ...baseProps,
    paymentMethods: [
      makePaymentMethod({ id: 'pm-cash', is_active: true }),
      makePaymentMethod({ id: 'pm-card', is_active: true }),
    ],
    paymentRepositories: [makePaymentRepository({ id: 'repo-cash' })],
  };

  it('other-payments control is icon-only with its accessible name via aria-label + title (never clips)', () => {
    render(<PaymentSummary {...readyProps} />);

    // Accessible name must be queryable by role — this is what a screen
    // reader announces for the icon-only control.
    const otherButton = screen.getByRole('button', {
      name: 'pos:payment.advancedPayments',
    });
    expect(otherButton).toHaveAttribute(
      'aria-label',
      'pos:payment.advancedPayments',
    );
    // Sighted tooltip for mouse/long-press users.
    expect(otherButton).toHaveAttribute(
      'title',
      'pos:payment.advancedPayments',
    );
    // Icon-only: the label must NOT be rendered as visible text — visible
    // text is exactly what clipped to "Autres paieme…" at real cart width.
    expect(otherButton).not.toHaveTextContent('pos:payment.advancedPayments');
  });

  it('cash button keeps its full visible label', () => {
    render(<PaymentSummary {...readyProps} />);

    const cashButton = screen.getByRole('button', { name: /cashPayment/i });
    // The dominant cash action keeps its visible text label (not icon-only).
    expect(cashButton).toHaveTextContent('pos:payment.cashPayment');
    expect(cashButton).not.toBeDisabled();
  });

  it('other-payments control still invokes onAdvancedPayments on click', () => {
    const onAdvancedPayments = vi.fn();
    render(
      <PaymentSummary {...readyProps} onAdvancedPayments={onAdvancedPayments} />,
    );

    fireEvent.click(
      screen.getByRole('button', { name: 'pos:payment.advancedPayments' }),
    );
    expect(onAdvancedPayments).toHaveBeenCalledTimes(1);
  });

  it('other-payments control respects the disabled prop (empty cart)', () => {
    const onAdvancedPayments = vi.fn();
    render(
      <PaymentSummary
        {...readyProps}
        onAdvancedPayments={onAdvancedPayments}
        disabled
      />,
    );

    const otherButton = screen.getByRole('button', {
      name: 'pos:payment.advancedPayments',
    });
    expect(otherButton).toBeDisabled();

    fireEvent.click(otherButton);
    expect(onAdvancedPayments).not.toHaveBeenCalled();
  });

  it('cash button label ellipsizes via a min-w-0 truncate span (F1: no double-sided hard clip at narrow footer width)', () => {
    render(<PaymentSummary {...readyProps} />);

    const cashButton = screen.getByRole('button', { name: /cashPayment/i });
    // The truncate prop must place text-overflow on a shrinkable label span
    // — NOT on the flex button root, where ellipsis never applies and the
    // label spills past both edges before overflow-hidden hard-clips it
    // (the 300px payment-footer-preview-narrow failure).
    const label = cashButton.querySelector('span.truncate');
    expect(label).not.toBeNull();
    expect(label).toHaveClass('min-w-0');
    expect(label).toHaveTextContent('pos:payment.cashPayment');
    expect(cashButton.classList.contains('truncate')).toBe(false);
  });

  it('disabled other-payments control keeps a visible sunken surface, symmetric with the disabled cash button (F2)', () => {
    render(<PaymentSummary {...readyProps} disabled />);

    const otherButton = screen.getByRole('button', {
      name: 'pos:payment.advancedPayments',
    });
    const cashButton = screen.getByRole('button', { name: /cashPayment/i });
    expect(otherButton).toBeDisabled();
    expect(cashButton).toBeDisabled();
    // Both halves of the payment action row must share the disabled recipe:
    // a visible sunken chip, never a transparent square on the navy footer.
    expect(otherButton.className).toContain('disabled:bg-surface-sunken');
    expect(otherButton.className).not.toContain('disabled:bg-transparent');
    expect(cashButton.className).toContain('disabled:bg-surface-sunken');
  });

  it('other-payments control is still hidden with fewer than 2 active methods', () => {
    render(
      <PaymentSummary
        {...baseProps}
        paymentMethods={[makePaymentMethod({ id: 'pm-cash', is_active: true })]}
        paymentRepositories={[makePaymentRepository({ id: 'repo-cash' })]}
      />,
    );

    expect(
      screen.queryByRole('button', { name: 'pos:payment.advancedPayments' }),
    ).not.toBeInTheDocument();
  });
});
