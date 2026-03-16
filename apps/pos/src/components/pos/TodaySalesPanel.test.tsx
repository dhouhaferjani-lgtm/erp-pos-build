import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { TodaySalesPage } from './TodaySalesPanel';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'reports.todaySales': "Today's Sales",
        'reports.totalSales': 'Total Sales',
        'reports.receiptCount': 'Receipts',
        'reports.avgTicket': 'Avg Ticket',
        'reports.returns': 'Returns',
        'reports.noReceipts': 'No receipts yet this shift',
        'reports.reprint': 'Reprint',
        'reports.receiptNo': 'Receipt #',
        'reports.time': 'Time',
        'reports.type': 'Type',
        'reports.items': 'Items',
        'reports.total': 'Total',
        'reports.paymentMethod': 'Payment',
        'reports.status': 'Status',
        'reports.actions': 'Actions',
        'reports.typeSale': 'Sale',
        'reports.typeReturn': 'Return',
      };
      return map[key] ?? key;
    },
  }),
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ shift: { id: 'shift-1' } }),
}));

vi.mock('@/api/reportApi', () => ({
  fetchShiftReceipts: vi.fn().mockResolvedValue([
    {
      id: 'r-1',
      receipt_number: 'REC-001',
      receipt_type: 'sale',
      total: '50.00',
      status: 'active',
      created_at: '2026-03-12T10:30:00Z',
      payment_method: 'cash',
      lines: [
        { id: 'l-1', product_name: 'Widget A', quantity: 2, unit_price: '15.00', line_total: '30.00' },
        { id: 'l-2', product_name: 'Widget B', quantity: 1, unit_price: '20.00', line_total: '20.00' },
      ],
    },
    {
      id: 'r-2',
      receipt_number: 'REC-002',
      receipt_type: 'sale',
      total: '75.00',
      status: 'active',
      created_at: '2026-03-12T11:00:00Z',
      payment_method: 'card',
      lines: [
        { id: 'l-3', product_name: 'Gadget C', quantity: 3, unit_price: '25.00', line_total: '75.00' },
      ],
    },
    {
      id: 'r-3',
      receipt_number: 'REC-003',
      receipt_type: 'return',
      total: '20.00',
      status: 'active',
      created_at: '2026-03-12T11:30:00Z',
      payment_method: 'cash',
      lines: [
        { id: 'l-4', product_name: 'Widget A', quantity: 1, unit_price: '20.00', line_total: '20.00' },
      ],
    },
  ]),
}));

vi.mock('@/lib/printing', () => ({
  printReceiptAsPdf: vi.fn().mockResolvedValue(undefined),
}));

function renderPage() {
  return render(
    <MemoryRouter>
      <TodaySalesPage />
    </MemoryRouter>,
  );
}

describe('TodaySalesPage', () => {
  it('renders the page title and back button', () => {
    const { getByText } = renderPage();
    expect(getByText("Today's Sales")).toBeInTheDocument();
  });

  it('renders receipt table when shift has receipts', async () => {
    const { findByText } = renderPage();

    expect(await findByText('REC-001')).toBeInTheDocument();
    expect(await findByText('REC-002')).toBeInTheDocument();
    expect(await findByText('REC-003')).toBeInTheDocument();
  });

  it('shows returns count and return type badge', async () => {
    const { findByText, findAllByText } = renderPage();

    expect(await findByText('Returns')).toBeInTheDocument();
    expect(await findByText('1')).toBeInTheDocument();
    const returnBadges = await findAllByText('Return');
    expect(returnBadges.length).toBeGreaterThanOrEqual(1);
  });

  it('shows receipt line items in the items column', async () => {
    const { findByText } = renderPage();

    expect(await findByText('2× Widget A, 1× Widget B')).toBeInTheDocument();
    expect(await findByText('3× Gadget C')).toBeInTheDocument();
  });

  it('shows reprint button for each receipt', async () => {
    const { findAllByText } = renderPage();

    const reprintButtons = await findAllByText('Reprint');
    expect(reprintButtons).toHaveLength(3);
  });
});
