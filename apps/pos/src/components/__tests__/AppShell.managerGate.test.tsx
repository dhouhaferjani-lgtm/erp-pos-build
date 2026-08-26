/**
 * B-13 (iv) — manager-gated ROUTES compose from the PIN operator only.
 *
 * The scenario the owner ruled on: an IziPOS terminal signed in ONCE with the
 * business owner's back-office account, then handed to a cashier who
 * identifies with a PIN. Before this lane `hasManagerAccess` MAXed the two
 * identities, so that cashier cleared every manager-only route.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({ t: (key: string) => key }),
}));

let mockOperator: { id: string; name: string; roles: string[] } | null = null;
let mockUserRoles: string[] | undefined;

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: Object.assign(
    <T,>(selector: (s: unknown) => T): T =>
      selector({ operator: mockOperator, lock: vi.fn(), resetActivityTimer: vi.fn() }),
    { getState: () => ({ lastActivity: Date.now() }) },
  ),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: <T,>(selector: (s: unknown) => T): T =>
    selector({ companyId: 'co-1', user: { id: 'u-1', roles: mockUserRoles } }),
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
vi.mock('../NavRail', () => ({
  NavRail: (props: { items: { id: string; label: string }[] }) => (
    <nav data-testid="nav-rail">
      {props.items.map((i) => (
        <span key={i.id} data-testid={`nav-${i.id}`}>{i.label}</span>
      ))}
    </nav>
  ),
}));
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

async function renderAt(path: string) {
  const view = render(
    <MemoryRouter initialEntries={[path]}>
      <AppShell />
    </MemoryRouter>,
  );
  // Route elements are lazy() — let the dynamic import resolve.
  await screen.findByTestId('nav-rail');
  return view;
}

const MANAGER_ROUTES = ['/reports', '/shift', '/reports/z'] as const;

describe('AppShell manager-gated routes', () => {
  beforeEach(() => {
    mockOperator = null;
    mockUserRoles = undefined;
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
    expect(screen.queryByTestId('nav-shift')).toBeNull();
    expect(screen.getByTestId('nav-caisse')).toBeInTheDocument();
  });

  it('allows the manager routes to a manager PIN operator', async () => {
    mockOperator = { id: 'op-1', name: 'Manager', roles: ['manager'] };
    mockUserRoles = ['owner'];

    const reports = await renderAt('/reports');
    expect(await screen.findByTestId('page-reports')).toBeInTheDocument();
    reports.unmount();

    const shiftView = await renderAt('/shift');
    expect(await screen.findByTestId('page-shift')).toBeInTheDocument();
    expect(screen.getByTestId('nav-shift')).toBeInTheDocument();
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
});
