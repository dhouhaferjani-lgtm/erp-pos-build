/**
 * B-13 (ii) — the X report participates in the blind cash count regime.
 *
 * Before this lane the X report rendered per-tender CASH takings with no
 * policy gate at all, which defeated the blind count everywhere else on the
 * device: expected cash = opening float + cash takings, and this surface
 * handed over the second term.
 *
 * The mask is DISPLAY-ONLY. The signed `X_REPORT` fiscal event is authored in
 * `api/reportApi.ts` (whose `paymentMethodTotals` mapping is an explicit
 * three-field allow-list) and is byte-identical whether or not figures are
 * hidden.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (v: string) => `${v} TND`, decimals: 3 }),
}));

import { XReportModal } from '../XReportModal';
import type { XReportResponse, PaymentMethodItem } from '@/api/reportApi';

function makeReport(payment_methods: PaymentMethodItem[]): XReportResponse {
  return {
    id: 'x-1',
    terminal_id: 'term-1',
    shift_id: 'shift-1',
    generated_by: 'local',
    generated_at: '2026-08-26T10:00:00.000Z',
    sales_count: 4,
    gross_sales: '480.000',
    net_sales: '400.000',
    tax_amount: '80.000',
    refunds_count: 0,
    refunds_amount: '0.000',
    vat_breakdown: [],
    payment_methods,
  };
}

const TENDERS: PaymentMethodItem[] = [
  { payment_type: 'CASH', total_amount: '300.000', transaction_count: 3, is_physical: true },
  { payment_type: 'CARD', total_amount: '180.000', transaction_count: 1, is_physical: false },
];

function renderModal(
  concealPhysicalTenders: boolean,
  payment_methods: PaymentMethodItem[] = TENDERS,
) {
  return render(
    <XReportModal
      isOpen
      onClose={vi.fn()}
      report={makeReport(payment_methods)}
      isLoading={false}
      error={null}
      concealPhysicalTenders={concealPhysicalTenders}
    />,
  );
}

function tenderRow(code: string): HTMLElement {
  const cell = screen.getByText(code);
  const row = cell.closest('tr');
  if (row === null) throw new Error(`no row for tender ${code}`);
  return row;
}

describe('XReportModal blind-count concealment', () => {
  it('shows every tender amount when the policy discloses', () => {
    renderModal(false);
    expect(within(tenderRow('CASH')).getByText('300.000 TND')).toBeInTheDocument();
    expect(within(tenderRow('CARD')).getByText('180.000 TND')).toBeInTheDocument();
    expect(screen.queryByText('reports.dashboard.cashConcealed')).toBeNull();
  });

  it('masks the physical tender and explains why', () => {
    renderModal(true);
    expect(within(tenderRow('CASH')).getByText('—')).toBeInTheDocument();
    expect(within(tenderRow('CASH')).queryByText('300.000 TND')).toBeNull();
    expect(screen.getByText('reports.dashboard.cashConcealed')).toBeInTheDocument();
  });

  it('leaves an explicitly NON-physical tender visible', () => {
    renderModal(true);
    expect(within(tenderRow('CARD')).getByText('180.000 TND')).toBeInTheDocument();
  });

  it('keeps the transaction COUNT visible on a concealed tender', () => {
    // The count is not a term of the drawer expectation, and the X report's
    // reason to exist for the operator is "did my sales land?".
    renderModal(true);
    expect(within(tenderRow('CASH')).getByText('3')).toBeInTheDocument();
  });

  /**
   * Gate r1 F-1 — the mask must fail CLOSED on an unresolvable tender.
   *
   * `is_physical` is absent on the SERVER X builder (`/pos/reports/x` →
   * `XReportResource`, which has no such field) and on any row whose payment
   * method is missing from the device's synced method table (deactivated or
   * renamed). Treating "unknown" as "not physical" would render cash in full
   * in the one regime whose entire purpose is concealment.
   */
  it('conceals a tender whose is_physical is MISSING (unknown ⇒ conceal)', () => {
    renderModal(true, [
      { payment_type: 'CASH', total_amount: '300.000', transaction_count: 3 },
      { payment_type: 'MYSTERY', total_amount: '20.000', transaction_count: 1 },
    ]);
    expect(within(tenderRow('CASH')).getByText('—')).toBeInTheDocument();
    expect(within(tenderRow('MYSTERY')).getByText('—')).toBeInTheDocument();
    expect(screen.getByText('reports.dashboard.cashConcealed')).toBeInTheDocument();
  });

  it('discloses an is_physical-less report when the policy discloses', () => {
    // Fail-closed applies to the CONCEAL regime only; a missing flag must not
    // start hiding figures on a tenant that has blind counting switched off.
    renderModal(false, [
      { payment_type: 'CASH', total_amount: '300.000', transaction_count: 3 },
    ]);
    expect(within(tenderRow('CASH')).getByText('300.000 TND')).toBeInTheDocument();
  });

  it('does not render the concealment note when no tender row is masked', () => {
    renderModal(true, [
      { payment_type: 'CARD', total_amount: '180.000', transaction_count: 1, is_physical: false },
    ]);
    expect(screen.queryByText('reports.dashboard.cashConcealed')).toBeNull();
    expect(within(tenderRow('CARD')).getByText('180.000 TND')).toBeInTheDocument();
  });
});
