import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: vi.fn() },
  useTranslation: () => ({ t: (key: string) => key }),
}));

let mockOperator: { name: string; roles: string[]; id: string } | null = {
  name: 'Mgr',
  roles: ['manager'],
  id: 'op-1',
};

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: <T,>(selector: (s: unknown) => T): T =>
    selector({ operator: mockOperator }),
}));

import { ReportsMenu } from '../ReportsMenu';

function renderMenu() {
  return render(
    <ReportsMenu
      isOpen
      onClose={vi.fn()}
      onXReport={vi.fn()}
      onTransactionHistory={vi.fn()}
      onCashDrawerOps={vi.fn()}
      onTodaySales={vi.fn()}
      onZReportHistory={vi.fn()}
    />,
  );
}

describe('ReportsMenu manager gating', () => {
  beforeEach(() => {
    mockOperator = { name: 'Mgr', roles: ['manager'], id: 'op-1' };
  });

  it('shows all reports (incl. X-report, cash-drawer, Z-history) for a manager', () => {
    renderMenu();
    expect(screen.getByText('reports.xReport')).toBeInTheDocument();
    expect(screen.getByText('reports.cashDrawer')).toBeInTheDocument();
    expect(screen.getByText('reports.zList.title')).toBeInTheDocument();
    expect(screen.getByText('reports.transactionHistory')).toBeInTheDocument();
    expect(screen.getByText('reports.todaySales')).toBeInTheDocument();
  });

  it('hides manager-only reports for a cashier, keeps history + today sales', () => {
    mockOperator = { name: 'Cash', roles: ['cashier'], id: 'op-2' };
    renderMenu();
    // Manager-only — hidden
    expect(screen.queryByText('reports.xReport')).toBeNull();
    expect(screen.queryByText('reports.cashDrawer')).toBeNull();
    expect(screen.queryByText('reports.zList.title')).toBeNull();
    // Open to everyone
    expect(screen.getByText('reports.transactionHistory')).toBeInTheDocument();
    expect(screen.getByText('reports.todaySales')).toBeInTheDocument();
  });

  it('treats a missing operator as non-manager', () => {
    mockOperator = null;
    renderMenu();
    expect(screen.queryByText('reports.xReport')).toBeNull();
    expect(screen.queryByText('reports.cashDrawer')).toBeNull();
    expect(screen.queryByText('reports.zList.title')).toBeNull();
    expect(screen.getByText('reports.todaySales')).toBeInTheDocument();
  });
});
