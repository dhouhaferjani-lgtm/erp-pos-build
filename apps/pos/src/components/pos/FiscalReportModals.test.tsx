import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { XReportModal } from './XReportModal';
import { ZReportModal } from './ZReportModal';
import type { XReportResponse, ZReportResponse } from '@/api/reportApi';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'reports.xReportTitle': 'X Report',
        'reports.zReportTitle': 'Z Report',
        'reports.generatedAt': 'Generated',
        'reports.salesCount': 'Receipts',
        'reports.grossSales': 'Gross Sales',
        'reports.netSales': 'Net Sales',
        'reports.taxAmount': 'VAT',
        'reports.vatBreakdown': 'VAT Breakdown',
        'reports.vatRate': 'Rate',
        'reports.vatNet': 'Net',
        'reports.vatVat': 'VAT',
        'reports.vatGross': 'Gross',
        'reports.paymentBreakdown': 'Payments',
        'reports.paymentType': 'Method',
        'reports.paymentCount': 'Count',
        'reports.paymentAmount': 'Amount',
        'reports.fiscalHash': 'Fiscal hash',
        'reports.cashReconciliation': 'Cash Reconciliation',
        'reports.openingCash': 'Opening Cash',
        'reports.expectedCash': 'Expected Cash',
        'reports.variance': 'Variance',
        'reports.noVariance': 'No variance',
        'reports.varianceNote': 'No cash variance.',
        'reports.refundsCount': 'Refunds',
        'reports.vatOnSales': 'VAT on sales',
        'reports.vatOnRefunds': 'VAT on refunds',
        'reports.netVat': 'Net VAT',
        'reports.taxAmountNetOfRefunds': 'VAT (net of refunds)',
        'reports.vatBreakdownNetOfRefunds': 'VAT Breakdown (net of refunds)',
        'reports.vatUnreconciled': 'VAT could not be reconciled',
      };
      return map[key] ?? key;
    },
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (v: number | string) => String(v),
    // The modals derive the VAT disclosure at the CURRENCY scale (rule 19), so
    // the mock has to supply one — an undefined scale would silently fall back
    // to decimal.ts's default of 3 and mis-render every scale-2 currency.
    decimals: 2,
  }),
}));

const xReport: XReportResponse = {
  id: 'x-1',
  terminal_id: 'term-1',
  shift_id: 'shift-1',
  generated_by: 'local',
  generated_at: '2026-07-09T10:00:00Z',
  sales_count: 1,
  gross_sales: '119.00',
  net_sales: '100.00',
  tax_amount: '19.00',
  refunds_count: 0,
  refunds_amount: '0.00',
  vat_breakdown: [
    { tax_rate: 19.1234, net_amount: '100.00', vat_amount: '19.00', gross_amount: '119.00' },
  ],
  payment_methods: [],
};

const zReport: ZReportResponse = {
  id: 'z-1',
  terminal_id: 'term-1',
  shift_id: 'shift-1',
  z_number: 1,
  fiscal_hash: 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
  previous_z_hash: null,
  generated_by: 'local',
  generated_at: '2026-07-09T10:00:00Z',
  is_first_z_report: true,
  formatted_z_number: 'Z0001',
  sales_count: 1,
  gross_sales: '119.00',
  opening_cash: '100.00',
  expected_cash: '219.00',
  actual_cash: '219.00',
  variance: '0.00',
  has_variance: false,
  report_data: {
    sales_count: 1,
    gross_sales: '119.00',
    net_sales: '100.00',
    tax_amount: '19.00',
    refunds_count: 0,
    refunds_amount: '0.00',
    voided_count: 0,
    opening_cash: '100.00',
    expected_cash: '219.00',
    vat_breakdown: [
      { tax_rate: 19.1234, net_amount: '100.00', vat_amount: '19.00', gross_amount: '119.00' },
    ],
    payment_methods: [],
  },
};

/** These cases assert VAT display, not B-13 concealment. */
const NO_CONCEAL: ReadonlySet<string> = new Set<string>();

describe('fiscal report modal VAT rate display', () => {
  it('formats X-report VAT rates without changing the report payload type', () => {
    render(
      <XReportModal
        isOpen
        onClose={vi.fn()}
        report={xReport}
        isLoading={false}
        error={null}
        concealedTenderCodes={NO_CONCEAL}
      />,
    );

    expect(screen.getByText('19.12%')).toBeInTheDocument();
    expect(screen.queryByText('19.1234%')).not.toBeInTheDocument();
    expect(typeof xReport.vat_breakdown[0]!.tax_rate).toBe('number');
  });

  it('formats Z-report VAT rates without changing the signed report payload type', () => {
    render(
      <ZReportModal
        isOpen
        onClose={vi.fn()}
        onConfirmGenerate={vi.fn()}
        report={zReport}
        isLoading={false}
        error={null}
      />,
    );

    expect(screen.getByText('19.12%')).toBeInTheDocument();
    expect(screen.queryByText('19.1234%')).not.toBeInTheDocument();
    expect(typeof zReport.report_data.vat_breakdown[0]!.tax_rate).toBe('number');
  });
});

/**
 * B-6(ii) / Option A1 — the X and Z modals used to print a SALE-ONLY headline
 * VAT card directly above a per-rate table that is NET of refunds: two numbers
 * on one report disagreeing by exactly the refund VAT, with nothing on screen
 * bridging them.
 */
describe('fiscal report modal refund-VAT disclosure (B-6(ii))', () => {
  /** 57.00 sale VAT, 47.50 net table ⇒ 9.50 refund VAT. */
  const refundBearingX: XReportResponse = {
    ...xReport,
    tax_amount: '57.00',
    refunds_count: 1,
    refunds_amount: '59.50',
    vat_breakdown: [
      { tax_rate: 19, net_amount: '250.00', vat_amount: '47.50', gross_amount: '297.50' },
    ],
  };

  const refundBearingZ: ZReportResponse = {
    ...zReport,
    report_data: {
      ...zReport.report_data,
      tax_amount: '57.00',
      refunds_count: 1,
      refunds_amount: '59.50',
      vat_breakdown: [
        { tax_rate: 19, net_amount: '250.00', vat_amount: '47.50', gross_amount: '297.50' },
      ],
    },
  };

  it('X report: shows the three-line bridge and puts the NET figure in the headline card', () => {
    render(
      <XReportModal isOpen onClose={vi.fn()} report={refundBearingX} isLoading={false} error={null} concealedTenderCodes={NO_CONCEAL} />,
    );

    expect(screen.getByText('VAT on sales')).toBeInTheDocument();
    expect(screen.getByText('57.00')).toBeInTheDocument();
    expect(screen.getByText('VAT on refunds')).toBeInTheDocument();
    expect(screen.getByText('-9.50')).toBeInTheDocument();
    expect(screen.getByText('Net VAT')).toBeInTheDocument();
    // Headline card is relabelled and carries the net figure, not the sale-only one.
    expect(screen.getByText('VAT (net of refunds)')).toBeInTheDocument();
    // refunds_amount is now surfaced (it was dropped before B-6(ii)).
    expect(screen.getByText('59.50')).toBeInTheDocument();
  });

  it('Z report: shows the three-line bridge', () => {
    render(
      <ZReportModal
        isOpen
        onClose={vi.fn()}
        onConfirmGenerate={vi.fn()}
        report={refundBearingZ}
        isLoading={false}
        error={null}
      />,
    );

    expect(screen.getByText('VAT on sales')).toBeInTheDocument();
    expect(screen.getByText('VAT on refunds')).toBeInTheDocument();
    expect(screen.getByText('Net VAT')).toBeInTheDocument();
    expect(screen.getByText('VAT (net of refunds)')).toBeInTheDocument();
  });

  it('renders NO disclosure on a refund-free shift, where the two figures already agree', () => {
    render(
      <XReportModal isOpen onClose={vi.fn()} report={xReport} isLoading={false} error={null} concealedTenderCodes={NO_CONCEAL} />,
    );

    expect(screen.queryByText('VAT on refunds')).not.toBeInTheDocument();
    expect(screen.queryByText('Net VAT')).not.toBeInTheDocument();
    // The single historical VAT card is untouched — unqualified label, no
    // "(net of refunds)" suffix. (`getAllByText`: the per-rate table header
    // legitimately carries the same word.)
    expect(screen.getAllByText('VAT').length).toBeGreaterThan(0);
    expect(screen.queryByText('VAT (net of refunds)')).not.toBeInTheDocument();
  });
});

/**
 * GATE r1 B-1/F-1 — the unreconciled warning was PROVABLY unreachable: the
 * derivation made `isReconciled === false` imply `hasRefundVat === false`, and
 * the component early-returned on `!hasRefundVat`. On the one shift shape the
 * safety net exists for, the modal rendered no disclosure at all and fell back
 * to the raw sale-only `tax_amount` — the pre-B-6(ii) defect, with no trace.
 */
describe('fiscal report modal unreconciled disclosure (gate r1 F-1)', () => {
  /** Net table (13.00) LARGER than the sale-only headline (10.00) — a corpus anomaly. */
  const anomalousX: XReportResponse = {
    ...xReport,
    tax_amount: '10.00',
    refunds_count: 0,
    refunds_amount: '0.00',
    vat_breakdown: [
      { tax_rate: 19, net_amount: '68.42', vat_amount: '13.00', gross_amount: '81.42' },
    ],
  };

  it('X report: renders the unreconciled warning on a negative wedge', () => {
    render(
      <XReportModal isOpen onClose={vi.fn()} report={anomalousX} isLoading={false} error={null} concealedTenderCodes={NO_CONCEAL} />,
    );

    expect(screen.getByText('VAT could not be reconciled')).toBeInTheDocument();
  });

  it('X report: shows both real figures and NO fabricated refund line', () => {
    render(
      <XReportModal isOpen onClose={vi.fn()} report={anomalousX} isLoading={false} error={null} concealedTenderCodes={NO_CONCEAL} />,
    );

    // Both figures are real data and both are shown, so the reader can see the
    // disagreement the warning names.
    expect(screen.getByText('VAT on sales')).toBeInTheDocument();
    expect(screen.getByText('Net VAT')).toBeInTheDocument();
    // A "-0.00" refund line would assert a refund that did not happen.
    expect(screen.queryByText('VAT on refunds')).not.toBeInTheDocument();
    expect(screen.queryByText('-0.00')).not.toBeInTheDocument();
  });

  it('Z report: renders the unreconciled warning on a negative wedge', () => {
    render(
      <ZReportModal
        isOpen
        onClose={vi.fn()}
        onConfirmGenerate={vi.fn()}
        report={{
          ...zReport,
          report_data: {
            ...zReport.report_data,
            tax_amount: '10.00',
            vat_breakdown: [
              { tax_rate: 19, net_amount: '68.42', vat_amount: '13.00', gross_amount: '81.42' },
            ],
          },
        }}
        isLoading={false}
        error={null}
      />,
    );

    expect(screen.getByText('VAT could not be reconciled')).toBeInTheDocument();
  });

  it('stays silent on a clean refund-free shift (the warning is not always-on)', () => {
    render(
      <XReportModal isOpen onClose={vi.fn()} report={xReport} isLoading={false} error={null} concealedTenderCodes={NO_CONCEAL} />,
    );

    expect(screen.queryByText('VAT could not be reconciled')).not.toBeInTheDocument();
    expect(screen.queryByText('VAT on sales')).not.toBeInTheDocument();
  });
});
