/**
 * B-13 (iv) — manager-gated ROUTES compose from the PIN operator only.
 *
 * The scenario the owner ruled on: an IziPOS terminal signed in ONCE with the
 * business owner's back-office account, then handed to a cashier who
 * identifies with a PIN. Before this lane `hasManagerAccess` MAXed the two
 * identities, so that cashier cleared every manager-only route.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({ t: (key: string) => key }),
}));

let mockOperator: { id: string; name: string; roles: string[]; permissions?: string[]; authority_stale?: boolean } | null = null;
let mockUserRoles: string[] | undefined;
let mockCompanyId = 'co-1';

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: Object.assign(
    <T,>(selector: (s: unknown) => T): T =>
      selector({ operator: mockOperator, lock: vi.fn(), resetActivityTimer: vi.fn() }),
    { getState: () => ({ lastActivity: Date.now() }) },
  ),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: <T,>(selector: (s: unknown) => T): T =>
    selector({ companyId: mockCompanyId, user: { id: 'u-1', roles: mockUserRoles } }),
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: Object.assign(
    <T,>(selector: (s: unknown) => T): T =>
      selector({ theme: 'light', setTheme: vi.fn(), cartPosition: 'end' }),
    { getState: () => ({ inactivityTimeout: 0 }) },
  ),
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: Object.assign(<T,>(selector: (s: unknown) => T): T => selector({}), {
    getState: () => ({ lastSyncAt: Date.now(), triggerSync: vi.fn() }),
  }),
}));

// Child surfaces are irrelevant to the routing decision — stub them all so the
// assertion is purely "which route element rendered".
vi.mock('../Header', () => ({ Header: () => <div data-testid="header" /> }));
vi.mock('../TrainingModeBanner', () => ({ TrainingModeBanner: () => null }));
vi.mock('../C2MigrationBanner', () => ({ C2MigrationBanner: () => null }));
vi.mock('../RemoteShiftCloseBanner', () => ({ RemoteShiftCloseBanner: () => null }));
vi.mock('../fiscal/UnsyncedRiskIndicator', () => ({ UnsyncedRiskIndicator: () => null }));
vi.mock('../fiscal/DurabilityGateModal', () => ({ DurabilityGateModal: () => null }));
vi.mock('@/pages/HomePage', () => ({ HomePage: () => <div data-testid="page-home" /> }));
vi.mock('@/pages/SettingsPage', () => ({ SettingsPage: () => <div data-testid="page-settings" /> }));
vi.mock('@/components/pos/TodaySalesPanel', () => ({
  TodaySalesPage: () => <div data-testid="page-sales" />,
}));
vi.mock('@/pages/ReportsPage', () => ({ ReportsPage: () => <div data-testid="page-reports" /> }));
vi.mock('@/pages/ShiftClosurePage', () => ({
  ShiftClosurePage: () => <div data-testid="page-shift" />,
}));
vi.mock('@/pages/ZReportListPage', () => ({
  ZReportListPage: () => <div data-testid="page-zlist" />,
}));
vi.mock('@/pages/CustomersPage', () => ({ CustomersPage: () => <div data-testid="page-customers" /> }));
vi.mock('@/hooks/useCustomerDisplaySync', () => ({ useCustomerDisplaySync: () => {} }));
vi.mock('@/hooks/useCatalogChannel', () => ({ useCatalogChannel: () => {} }));
vi.mock('@/hooks/useFiscalDurabilityPolling', () => ({ useFiscalDurabilityPolling: () => {} }));
vi.mock('@/lib/fiscal/durabilityServiceFactory', () => ({
  buildOffDeviceDurabilityService: () => null,
}));
vi.mock('@/lib/db', () => ({ getDatabase: vi.fn().mockResolvedValue({}) }));

import { AppShell } from '../AppShell';

function LocationProbe() {
  return <output aria-label="Current route">{useLocation().pathname}</output>;
}

async function renderAt(path: string) {
  const view = render(
    <MemoryRouter initialEntries={[path]}>
      <AppShell />
      <LocationProbe />
    </MemoryRouter>,
  );
  // Route elements are lazy() — let the dynamic import resolve.
  await screen.findByRole('navigation', { name: 'nav.ariaLabel' });
  return view;
}

const MANAGER_ROUTES = ['/reports', '/shift', '/reports/z'] as const;

describe('AppShell manager-gated routes', () => {
  beforeEach(() => {
    mockOperator = null;
    mockUserRoles = undefined;
    mockCompanyId = 'co-1';
  });

  it('refuses every manager route to a cashier PIN on an OWNER-logged-in terminal', async () => {
    mockOperator = { id: 'op-2', name: 'Cashier', roles: ['cashier'] };
    mockUserRoles = ['owner'];

    for (const path of MANAGER_ROUTES) {
      const { unmount } = await renderAt(path);
      // Redirected to "/" — the Caisse, not the gated page.
      expect(await screen.findByTestId('page-home')).toBeInTheDocument();
      expect(screen.queryByTestId('page-reports')).toBeNull();
      expect(screen.queryByTestId('page-shift')).toBeNull();
      expect(screen.queryByTestId('page-zlist')).toBeNull();
      unmount();
    }
  });

  it('hides the Caisse-Shift nav destination for a cashier PIN on an owner terminal', async () => {
    mockOperator = { id: 'op-2', name: 'Cashier', roles: ['cashier'] };
    mockUserRoles = ['owner'];
    await renderAt('/');
    expect(screen.queryByRole('button', { name: 'nav.shift' })).toBeNull();
    expect(screen.getByRole('button', { name: 'nav.caisse' })).toBeInTheDocument();
  });

  it('allows the manager routes to a manager PIN operator', async () => {
    mockOperator = { id: 'op-1', name: 'Manager', roles: ['manager'] };
    mockUserRoles = ['owner'];

    const reports = await renderAt('/reports');
    expect(await screen.findByTestId('page-reports')).toBeInTheDocument();
    reports.unmount();

    const shiftView = await renderAt('/shift');
    expect(await screen.findByTestId('page-shift')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'nav.shift' })).toBeInTheDocument();
    shiftView.unmount();

    await renderAt('/reports/z');
    expect(await screen.findByTestId('page-zlist')).toBeInTheDocument();
  });

  it('keeps the owner-as-PIN-operator path working (single-account terminal)', async () => {
    // The owner sets up their OWN pin (App.tsx PinSetupPage seeds it from the
    // logged-in user), so their operator carries the owner role and nothing is
    // lost by dropping the login-user leg.
    mockOperator = { id: 'u-1', name: 'Owner', roles: ['owner'] };
    mockUserRoles = ['owner'];
    await renderAt('/shift');
    expect(await screen.findByTestId('page-shift')).toBeInTheDocument();
  });

  it.each(['co-1', 'co-2'])('opens permitted sales when a cashier selects Reports in %s', async (companyId) => {
    mockCompanyId = companyId;
    mockOperator = {
      id: 'op-cashier', name: 'Cashier', roles: ['cashier'],
      permissions: ['pos.view_receipts', 'pos.manage_shifts', 'pos.generate_z_report'],
    };
    mockUserRoles = ['owner'];
    await renderAt('/');

    fireEvent.click(screen.getByRole('button', { name: 'nav.rapports' }));

    expect(await screen.findByTestId('page-sales')).toBeInTheDocument();
    expect(screen.getByRole('status', { name: 'Current route' })).toHaveTextContent('/sales');
    expect(screen.getByRole('button', { name: 'nav.rapports' })).toHaveAttribute('aria-current', 'page');
    expect(screen.queryByTestId('page-reports')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'nav.caisse' }));
    expect(await screen.findByTestId('page-home')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'nav.rapports' }));
    expect(await screen.findByTestId('page-sales')).toBeInTheDocument();
  });

  it('opens manager reports using the PIN permission even for a custom role', async () => {
    mockOperator = {
      id: 'op-supervisor', name: 'Supervisor', roles: ['custom-supervisor'],
      permissions: ['pos.view_reports'],
    };
    await renderAt('/');
    fireEvent.click(screen.getByRole('button', { name: 'nav.rapports' }));
    expect(await screen.findByTestId('page-reports')).toBeInTheDocument();
    expect(screen.getByRole('status', { name: 'Current route' })).toHaveTextContent('/reports');
  });

  it('keeps Reports usable without elevating stale manager authority', async () => {
    mockOperator = {
      id: 'op-supervisor', name: 'Supervisor', roles: ['manager'],
      permissions: ['pos.view_reports'], authority_stale: true,
    };
    await renderAt('/');
    fireEvent.click(screen.getByRole('button', { name: 'nav.rapports' }));
    expect(await screen.findByTestId('page-sales')).toBeInTheDocument();
    expect(screen.queryByTestId('page-reports')).not.toBeInTheDocument();
  });
});
