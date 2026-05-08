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
