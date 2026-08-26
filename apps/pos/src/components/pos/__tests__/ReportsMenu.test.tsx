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

let mockUserRoles: string[] | undefined;

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: <T,>(selector: (s: unknown) => T): T =>
    selector({ operator: mockOperator }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: <T,>(selector: (s: unknown) => T): T =>
    selector({ user: { id: 'u-1', roles: mockUserRoles } }),
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
    mockUserRoles = undefined;
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

  /**
   * B-13 (ii)+(iv): the X report authors a SIGNED `X_REPORT` fiscal event and
   * discloses per-tender CASH takings, so its menu entry must follow the PIN
   * operator — not the back-office account the terminal happens to be signed
   * in with.
   */
  it('hides the X-report from a cashier PIN even when the terminal is logged in as the OWNER', () => {
    mockOperator = { name: 'Cash', roles: ['cashier'], id: 'op-2' };
    mockUserRoles = ['owner'];
    renderMenu();
    expect(screen.queryByText('reports.xReport')).toBeNull();
    expect(screen.queryByText('reports.cashDrawer')).toBeNull();
    expect(screen.queryByText('reports.zList.title')).toBeNull();
    // Non-gated entries stay reachable — this is a gate, not a lockout.
    expect(screen.getByText('reports.todaySales')).toBeInTheDocument();
  });

  it('still shows the X-report to a manager PIN on the same owner-logged-in terminal', () => {
    mockOperator = { name: 'Mgr', roles: ['manager'], id: 'op-1' };
    mockUserRoles = ['owner'];
    renderMenu();
    expect(screen.getByText('reports.xReport')).toBeInTheDocument();
  });
});
