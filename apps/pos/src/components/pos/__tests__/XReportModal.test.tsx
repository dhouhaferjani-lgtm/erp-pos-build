/**
 * B-13 (ii) — the X report participates in the blind cash count regime.
 *
 * Before this lane the X report rendered per-tender CASH takings with no
 * policy gate at all, which defeated the blind count everywhere else on the
 * device: expected cash = opening float + cash takings, and this surface
 * handed over the second term.
 *
 * The mask is DISPLAY-ONLY. The signed `X_REPORT` fiscal event is authored in
 * `api/reportApi.ts` and is byte-identical whether or not figures are hidden.
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
import type { XReportResponse } from '@/api/reportApi';

const REPORT: XReportResponse = {
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
  payment_methods: [
    { payment_type: 'CASH', total_amount: '300.000', transaction_count: 3 },
    { payment_type: 'CARD', total_amount: '180.000', transaction_count: 1 },
  ],
};

function renderModal(concealedTenderCodes: ReadonlySet<string>) {
  return render(
    <XReportModal
      isOpen
      onClose={vi.fn()}
      report={REPORT}
      isLoading={false}
      error={null}
      concealedTenderCodes={concealedTenderCodes}
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
  it('shows every tender amount when nothing is concealed', () => {
    renderModal(new Set());
    expect(within(tenderRow('CASH')).getByText('300.000 TND')).toBeInTheDocument();
    expect(within(tenderRow('CARD')).getByText('180.000 TND')).toBeInTheDocument();
    expect(screen.queryByText('reports.dashboard.cashConcealed')).toBeNull();
  });

  it('masks the concealed tender amount and explains why', () => {
    renderModal(new Set(['CASH']));
    expect(within(tenderRow('CASH')).getByText('—')).toBeInTheDocument();
    expect(within(tenderRow('CASH')).queryByText('300.000 TND')).toBeNull();
    expect(screen.getByText('reports.dashboard.cashConcealed')).toBeInTheDocument();
  });

  it('leaves non-concealed tenders alone', () => {
    renderModal(new Set(['CASH']));
    expect(within(tenderRow('CARD')).getByText('180.000 TND')).toBeInTheDocument();
  });

  it('keeps the transaction COUNT visible on a concealed tender', () => {
    // The count is not a term of the drawer expectation, and the X report's
    // reason to exist for the operator is "did my sales land?".
    renderModal(new Set(['CASH']));
    expect(within(tenderRow('CASH')).getByText('3')).toBeInTheDocument();
  });

  it('does not render the concealment note when no PRESENT tender is masked', () => {
    // A physical tender the shift never used must not produce a note about
    // hidden figures that are not on screen.
    renderModal(new Set(['CHEQUE']));
    expect(screen.queryByText('reports.dashboard.cashConcealed')).toBeNull();
    expect(within(tenderRow('CASH')).getByText('300.000 TND')).toBeInTheDocument();
  });
});
