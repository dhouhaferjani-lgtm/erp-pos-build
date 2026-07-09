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
      };
      return map[key] ?? key;
    },
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (v: number | string) => String(v),
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

describe('fiscal report modal VAT rate display', () => {
  it('formats X-report VAT rates without changing the report payload type', () => {
    render(
      <XReportModal
        isOpen
        onClose={vi.fn()}
        report={xReport}
        isLoading={false}
        error={null}
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
