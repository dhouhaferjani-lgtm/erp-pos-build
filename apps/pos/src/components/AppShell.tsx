import { useEffect, useCallback, useMemo, useState, lazy, Suspense } from 'react';
import { Route, Routes, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { ShoppingCart, Users, BarChart3, Wallet } from 'lucide-react';
import type Database from '@tauri-apps/plugin-sql';
import { Header } from './Header';
import { NavRail } from './NavRail';
import { TrainingModeBanner } from './TrainingModeBanner';
import { C2MigrationBanner } from './C2MigrationBanner';
import { RemoteShiftCloseBanner } from './RemoteShiftCloseBanner';
import { UnsyncedRiskIndicator } from './fiscal/UnsyncedRiskIndicator';
import { DurabilityGateModal } from './fiscal/DurabilityGateModal';
import { HomePage } from '@/pages/HomePage';
import { useOperatorStore } from '@/stores/operatorStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { hasManagerAccess } from '@/lib/auth/roles';
import { useSyncStore } from '@/stores/syncStore';
import { useAuthStore } from '@/stores/authStore';
import { useCustomerDisplaySync } from '@/hooks/useCustomerDisplaySync';
import { useCatalogChannel } from '@/hooks/useCatalogChannel';
import { useFiscalDurabilityPolling } from '@/hooks/useFiscalDurabilityPolling';
import { buildOffDeviceDurabilityService } from '@/lib/fiscal/durabilityServiceFactory';
import { getDatabase } from '@/lib/db';

const SettingsPage = lazy(() =>
  import('@/pages/SettingsPage').then((m) => ({ default: m.SettingsPage })),
);

const TodaySalesPage = lazy(() =>
  import('@/components/pos/TodaySalesPanel').then((m) => ({ default: m.TodaySalesPage })),
);

const ReportsPage = lazy(() =>
  import('@/pages/ReportsPage').then((m) => ({ default: m.ReportsPage })),
);

const ShiftClosurePage = lazy(() =>
  import('@/pages/ShiftClosurePage').then((m) => ({ default: m.ShiftClosurePage })),
);

const ZReportListPage = lazy(() =>
  import('@/pages/ZReportListPage').then((m) => ({ default: m.ZReportListPage })),
);

const CustomersPage = lazy(() =>
  import('@/pages/CustomersPage').then((m) => ({ default: m.CustomersPage })),
);

/** Nav-rail destinations → routes. Settings is reached via the header gear. */
type NavDest = 'caisse' | 'clients' | 'rapports' | 'shift';
const NAV_ROUTE: Record<NavDest, string> = {
  caisse: '/',
  clients: '/customers',
  rapports: '/reports',
  shift: '/shift',
};
function activeDestForPath(pathname: string): NavDest {
  if (pathname.startsWith('/customers')) return 'clients';
  if (pathname === '/reports' || pathname.startsWith('/sales')) return 'rapports';
  if (pathname.startsWith('/shift') || pathname.startsWith('/reports/z')) return 'shift';
  return 'caisse';
}

export function AppShell() {
  const resetActivityTimer = useOperatorStore((s) => s.resetActivityTimer);
  const lock = useOperatorStore((s) => s.lock);
  const operator = useOperatorStore((s) => s.operator);
  const companyId = useAuthStore((s) => s.companyId);

  // Nav rail (P2): destinations + theme toggle, placed OPPOSITE the cart.
  const navigate = useNavigate();
  const location = useLocation();
  const { t } = useTranslation('pos');
  const theme = useSettingsStore((s) => s.theme);
  const setTheme = useSettingsStore((s) => s.setTheme);
  const cartPosition = useSettingsStore((s) => s.cartPosition);
  const railOnLeft = cartPosition === 'end'; // cart right ⇒ rail left
  // B-13 (iv): manager gating follows the ACTIVE PIN OPERATOR only. The
  // account the terminal is signed in with (usually the owner's) is a
  // provisioning identity and grants nothing to whoever holds the till.
  const isManager = hasManagerAccess(operator);
  const navItems: { id: NavDest; label: string; icon: React.ReactNode }[] = [
    { id: 'caisse', label: t('nav.caisse'), icon: <ShoppingCart className="h-5 w-5" /> },
    { id: 'clients', label: t('nav.clients'), icon: <Users className="h-5 w-5" /> },
    { id: 'rapports', label: t('nav.rapports'), icon: <BarChart3 className="h-5 w-5" /> },
    // Caisse-Shift → Z-report history: managers only.
    ...(isManager
      ? [{ id: 'shift' as const, label: t('nav.shift'), icon: <Wallet className="h-5 w-5" /> }]
      : []),
  ];
  const railNode = (
    <NavRail
      items={navItems}
      active={activeDestForPath(location.pathname)}
      // Cashiers have the sales-history surface used by the header reports
      // menu; /reports itself remains permission-gated for manager analytics.
      onSelect={(d) => navigate(d === 'rapports' && !isManager ? '/sales' : NAV_ROUTE[d])}
      theme={theme}
      onToggleTheme={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
      ariaLabel={t('nav.ariaLabel')}
      themeToggleLabel={t('nav.themeToggle')}
      brand={<span className="font-display text-xl font-extrabold text-accent">i</span>}
      // Seam border always faces the canvas: rail-on-left ⇒ border-right,
      // rail-on-right ⇒ border-left.
      seamSide={railOnLeft ? 'right' : 'left'}
    />
  );

  // Sync cart/checkout state to customer-facing display
  useCustomerDisplaySync();

  // Round-2 T32-B2: wire the spec §12 off-device durability surface.
  // The factory builds the service once the company DB is loaded; the
  // polling hook pushes results into the durability store; the indicator
  // + gate modal mount unconditionally and read from the store, so the
  // operator-visible conservation control is reachable as soon as the
  // POS shell is up.
  const [durabilityDb, setDurabilityDb] = useState<Database | null>(null);
  useEffect(() => {
    let cancelled = false;
    if (!companyId) return;
    void getDatabase(companyId).then((db) => {
      if (!cancelled) setDurabilityDb(db);
    });
    return () => {
      cancelled = true;
    };
  }, [companyId]);
  const durabilityService = useMemo(
    () => buildOffDeviceDurabilityService(durabilityDb),
    [durabilityDb],
  );
  useFiscalDurabilityPolling(durabilityService);

  // Bug 1 — subscribe to the company-level catalog channel so admin-side
  // product / menu mutations show up in the POS within ~500ms instead of
  // waiting for the 60s polling tick. The hook is a no-op if companyId
  // hasn't been set yet, so mounting unconditionally is safe.
  useCatalogChannel();

  const handleActivity = useCallback(() => {
    resetActivityTimer();
  }, [resetActivityTimer]);

  // T1.1 Step 1.4: connectivity monitoring moved to MainApp so LoginPage
  // (which mounts BEFORE AppShell in the route tree) can read isOnline.

  // Trigger sync when app regains visibility after >1 min
  useEffect(() => {
    const STALE_THRESHOLD_MS = 60_000; // 1 minute

    function handleVisibilityChange() {
      if (document.visibilityState === 'visible') {
        const { lastSyncAt, triggerSync } = useSyncStore.getState();
        const elapsed = lastSyncAt ? Date.now() - lastSyncAt : Infinity;
        if (elapsed > STALE_THRESHOLD_MS) {
          triggerSync();
        }
      }
    }

    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => {
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, []);

  useEffect(() => {
    if (!operator) return;

    const events = ['mousedown', 'keydown', 'touchstart'] as const;
    for (const event of events) {
      window.addEventListener(event, handleActivity);
    }

    const interval = setInterval(() => {
      const timeout = useSettingsStore.getState().inactivityTimeout;
      if (timeout === 0) return; // "Never" — skip check
      const { lastActivity } = useOperatorStore.getState();
      if (Date.now() - lastActivity > timeout * 1000) {
        lock();
      }
    }, 10_000);

    return () => {
      for (const event of events) {
        window.removeEventListener(event, handleActivity);
      }
      clearInterval(interval);
    };
  }, [operator, handleActivity, lock]);

  return (
    <div className="flex h-screen bg-surface-canvas">
      {railOnLeft && railNode}
      <div className="flex min-w-0 flex-1 flex-col">
        <Header />
      {/* T2.5 — sticky training-mode banner. Renders only when the
          active terminal has is_training_mode=true; otherwise null
          (no DOM, no layout impact). Sits BETWEEN Header and main so
          the cashier sees it on every screen, not just HomePage. */}
      <TrainingModeBanner />
      <C2MigrationBanner />
      {/* Phase 6.2 — advisory banner when the device's open shift was closed
          remotely (web-admin recovery). Renders null when no conflict; the
          background reconcile (syncService.runFullSync) sets/clears it. */}
      <RemoteShiftCloseBanner />
      {/* Round-2 T32-B2: spec §12 operator-visible unsynced-risk indicator.
          Exception-only: renders null for healthy/pre-first-poll (no DOM,
          no layout impact — the healthy "synced" signal lives in the Header
          status pill) and only surfaces a banner for elevated/escalated
          risk. The indicator owns its own spacing when it renders, so there
          is no permanent padding wrapper reserving space in the healthy case. */}
      <UnsyncedRiskIndicator />
      <main className="flex-1 overflow-hidden">
        <Suspense fallback={null}>
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/customers" element={<CustomersPage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="/sales" element={<TodaySalesPage />} />
            <Route
              path="/reports"
              element={isManager ? <ReportsPage /> : <Navigate to="/" replace />}
            />
            <Route
              path="/shift"
              element={isManager ? <ShiftClosurePage /> : <Navigate to="/" replace />}
            />
            {/* Z-report history = manager-only (point 1/2): defense-in-depth
                even though the nav item is hidden for cashiers. */}
            <Route
              path="/reports/z"
              element={isManager ? <ZReportListPage /> : <Navigate to="/" replace />}
            />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </main>
      </div>
      {!railOnLeft && railNode}
      {/* Round-2 T32-B2: spec §12 forced-archive blocking gate. When the
          durability store reports forceArchiveRequired AND no live
          acknowledgment grace covers the moment, this modal blocks the
          entire POS UI until the operator acknowledges. Renders null
          otherwise. */}
      <DurabilityGateModal />
    </div>
  );
}
