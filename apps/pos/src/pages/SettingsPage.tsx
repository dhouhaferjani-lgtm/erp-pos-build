import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { List, Table, Image, Globe, Printer, Search, CheckCircle, AlertCircle, Loader2, Hand, Maximize, Shield, RefreshCw, LogOut, Sun, Moon, Trash2, SlidersHorizontal, Tag, Droplet } from 'lucide-react';
import { cn } from '@/lib/utils';
import { tokens } from '@/lib/designTokens';
import { SegmentedControl } from '@/components/ui';
import { ACCENTS, type AccentName } from '@/lib/theme';
import { useSettingsStore, SUPPORTED_LANGUAGES } from '@/stores/settingsStore';
import { usePrinterStore } from '@/stores/printerStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { Modal } from '@/components/pos/Modal';
import { teardownPosSessionStores } from '@/lib/session/teardownPosSession';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';
import { hasManagerAccess } from '@/lib/auth/roles';
import { PageHeader } from '@/components/PageHeader';
import {
  discoverPrinters,
  printTestPage,
  getPrintSettingsFromStore,
  isTauriEnvironment,
} from '@/lib/printing';
import type { PrinterInfo, PrinterConfig } from '@/lib/printing';
import { applyFullscreen } from '@/lib/fullscreen';
import { CashDrawerSettings } from '@/components/settings/CashDrawerSettings';
import { ScannerSettings } from '@/components/settings/ScannerSettings';
import { PrinterAdvancedSettings } from '@/components/settings/PrinterAdvancedSettings';
import { CustomerDisplaySettings } from '@/components/settings/CustomerDisplaySettings';

const TIMEOUT_PRESETS = [
  { value: 30, labelKey: 'settings.timeout30s' },
  { value: 60, labelKey: 'settings.timeout1m' },
  { value: 120, labelKey: 'settings.timeout2m' },
  { value: 300, labelKey: 'settings.timeout5m' },
  { value: 600, labelKey: 'settings.timeout10m' },
  { value: 0, labelKey: 'settings.timeoutNever' },
] as const;

/**
 * Accent preview swatches. These literal values mirror the [data-accent] tokens
 * in index.css and are used only to paint the colour-picker dots (a swatch must
 * show its own colour). The live UI accent comes from the tokens, not these.
 */
const ACCENT_SWATCH: Record<string, string> = {
  orange: '#EA661A',
  green: '#1F8A5B',
  blue: '#2B6CC4',
  teal: '#0E8E80',
};


export function SettingsPage() {
  const { t } = useTranslation('pos');
  const navigate = useNavigate();

  const displayMode = useSettingsStore((s) => s.displayMode);
  const language = useSettingsStore((s) => s.language);
  const touchMode = useSettingsStore((s) => s.touchMode);
  const fullscreen = useSettingsStore((s) => s.fullscreen);
  const setDisplayMode = useSettingsStore((s) => s.setDisplayMode);
  const setLanguage = useSettingsStore((s) => s.setLanguage);
  const setTouchMode = useSettingsStore((s) => s.setTouchMode);
  const setFullscreen = useSettingsStore((s) => s.setFullscreen);
  const confirmLineDelete = useSettingsStore((s) => s.confirmLineDelete);
  const setConfirmLineDelete = useSettingsStore((s) => s.setConfirmLineDelete);
  const parapharmacySkinFiltersEnabled = useSettingsStore((s) => s.parapharmacySkinFiltersEnabled);
  const setParapharmacySkinFiltersEnabled = useSettingsStore((s) => s.setParapharmacySkinFiltersEnabled);
  const inactivityTimeout = useSettingsStore((s) => s.inactivityTimeout);
  const lockAfterSale = useSettingsStore((s) => s.lockAfterSale);
  const setInactivityTimeout = useSettingsStore((s) => s.setInactivityTimeout);
  const setLockAfterSale = useSettingsStore((s) => s.setLockAfterSale);
  // Appearance — theme foundation knobs (light/dark, accent, corners, density).
  const theme = useSettingsStore((s) => s.theme);
  const accent = useSettingsStore((s) => s.accent);
  const corner = useSettingsStore((s) => s.corner);
  const density = useSettingsStore((s) => s.density);
  const setTheme = useSettingsStore((s) => s.setTheme);
  const setAccent = useSettingsStore((s) => s.setAccent);
  const setCorner = useSettingsStore((s) => s.setCorner);
  const setDensity = useSettingsStore((s) => s.setDensity);
  // Task 16 — caisse display optional-field toggles (default off).
  const showSkuOnRows = useSettingsStore((s) => s.showSkuOnRows);
  const showSkinTypeOnTiles = useSettingsStore((s) => s.showSkinTypeOnTiles);
  const setShowSkuOnRows = useSettingsStore((s) => s.setShowSkuOnRows);
  const setShowSkinTypeOnTiles = useSettingsStore((s) => s.setShowSkinTypeOnTiles);

  const terminal = useTerminalStore((s) => s.terminal);
  const shift = useTerminalStore((s) => s.shift);

  const serverUrl = useAuthStore((s) => s.serverUrl);
  const unbindDevice = useAuthStore((s) => s.unbindDevice);

  const operator = useOperatorStore((s) => s.operator);
  // B-13 (iv): PIN operator only — device unbind is a manager action and the
  // terminal's login account must not confer it on a cashier.
  const isManager = hasManagerAccess(operator?.roles);

  const [showUnbindConfirm, setShowUnbindConfirm] = useState(false);

  // FIX 2 (MAJOR): re-check isManager at confirm time. If the operator role
  // changed between opening the modal and clicking confirm (e.g. role change
  // race), the destructive action must not proceed.
  const handleConfirmUnbind = async () => {
    if (!isManager) { setShowUnbindConfirm(false); return; }
    setShowUnbindConfirm(false);
    teardownPosSessionStores();
    await unbindDevice();
  };

  const isOnline = useConnectivityStore((s) => s.isOnline);

  const printerConfig = usePrinterStore((s) => s.printerConfig);
  const autoPrint = usePrinterStore((s) => s.autoPrint);
  const setPrinterConfig = usePrinterStore((s) => s.setPrinterConfig);
  const setAutoPrint = usePrinterStore((s) => s.setAutoPrint);
  const clearPrinterConfig = usePrinterStore((s) => s.clearPrinterConfig);

  const [discoveredPrinters, setDiscoveredPrinters] = useState<PrinterInfo[]>([]);
  const [isDiscovering, setIsDiscovering] = useState(false);
  const [isPrintingTest, setIsPrintingTest] = useState(false);
  const [printerStatus, setPrinterStatus] = useState<'idle' | 'success' | 'error'>('idle');
  const [printerMessage, setPrinterMessage] = useState('');
  const [forceFullscreenApplied, setForceFullscreenApplied] = useState(false);
  const isTauri = isTauriEnvironment();

  const handleDiscoverPrinters = useCallback(async () => {
    setIsDiscovering(true);
    setPrinterStatus('idle');
    setPrinterMessage('');
    try {
      const printers = await discoverPrinters();
      setDiscoveredPrinters(printers);
      if (printers.length === 0) {
        setPrinterStatus('error');
        setPrinterMessage(t('settings.noPrintersFound'));
      }
    } catch (err: unknown) {
      setPrinterStatus('error');
      setPrinterMessage(err instanceof Error ? err.message : String(err));
    } finally {
      setIsDiscovering(false);
    }
  }, [t]);

  const handleSelectPrinter = useCallback(
    (printer: PrinterInfo) => {
      const config: PrinterConfig = {
        connection_type: printer.connection_type,
        address: printer.address,
        name: printer.name,
      };
      setPrinterConfig(config);
      setPrinterStatus('success');
      setPrinterMessage(t('settings.printerConnected'));
    },
    [setPrinterConfig, t],
  );

  const handleTestPrint = useCallback(async () => {
    if (!printerConfig) return;
    setIsPrintingTest(true);
    setPrinterStatus('idle');
    setPrinterMessage('');
    try {
      const ps = getPrintSettingsFromStore();
      await printTestPage(printerConfig, ps.columns);
      setPrinterStatus('success');
      setPrinterMessage(t('settings.testPrintSent'));
    } catch (err: unknown) {
      setPrinterStatus('error');
      setPrinterMessage(err instanceof Error ? err.message : String(err));
    } finally {
      setIsPrintingTest(false);
    }
  }, [printerConfig, t]);

  // Section navigation (owner: use the full width + a way to move between
  // sections). Sticky left nav scrolls to / highlights each section.
  const [activeSection, setActiveSection] = useState('display');
  const sections = [
    { id: 'display', labelKey: 'settings.display' },
    { id: 'appearance', labelKey: 'settings.appearance' },
    { id: 'touch', labelKey: 'settings.touchDisplay' },
    { id: 'security', labelKey: 'settings.security' },
    { id: 'printer', labelKey: 'settings.printer' },
    { id: 'kitchen', labelKey: 'settings.kitchenPrinter' },
    { id: 'terminal', labelKey: 'settings.terminal' },
    ...(isManager ? [{ id: 'device-security', labelKey: 'settings.deviceSecurity' }] : []),
    { id: 'about', labelKey: 'settings.about' },
  ];
  const scrollToSection = (id: string) => {
    setActiveSection(id);
    document
      .getElementById(`settings-${id}`)
      ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  return (
    <div className="flex h-full flex-col bg-surface-canvas">
      <PageHeader
        title={t('settings.title')}
        onBack={() => navigate('/')}
        backLabel={t('common:back')}
      />

      <div className="flex min-h-0 flex-1 overflow-hidden">
        {/* Section nav — sticky left rail */}
        <nav
          aria-label={t('settings.title')}
          className="hidden w-56 shrink-0 overflow-y-auto border-r border-border-subtle bg-surface-raised p-3 lg:block"
        >
          <ul className="space-y-1">
            {sections.map((s) => (
              <li key={s.id}>
                <button
                  type="button"
                  onClick={() => scrollToSection(s.id)}
                  className={cn(
                    'flex min-h-12 w-full items-center rounded-ctl px-3 text-left text-sm font-medium transition-colors',
                    activeSection === s.id
                      ? 'bg-accent-tint text-accent-strong'
                      : 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
                  )}
                >
                  {t(s.labelKey)}
                </button>
              </li>
            ))}
          </ul>
        </nav>

        {/* Content */}
        <div className="min-h-0 flex-1 overflow-y-auto px-4 pt-4 pb-12">
          <div className="mx-auto max-w-3xl space-y-6">
          {/* Display Preferences */}
          <section id="settings-display" className="scroll-mt-4 rounded-card bg-surface-raised p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-ink">
              {t('settings.display')}
            </h2>

            {/* Display mode */}
            <div className="mb-4">
              <label className="mb-2 block text-sm font-medium text-ink-muted">
                {t('settings.displayMode')}
              </label>
              <div className={cn(tokens.segmented.root, 'flex')}>
                <button
                  onClick={() => setDisplayMode('vitrine')}
                  className={cn(
                    tokens.segmented.item,
                    'flex flex-1 items-center justify-center gap-2',
                    displayMode === 'vitrine'
                      ? tokens.segmented.itemActive
                      : tokens.segmented.itemInactive,
                  )}
                >
                  <Image className="h-4 w-4" />
                  {t('settings.vitrineMode')}
                </button>
                <button
                  onClick={() => setDisplayMode('liste')}
                  className={cn(
                    tokens.segmented.item,
                    'flex flex-1 items-center justify-center gap-2',
                    displayMode === 'liste'
                      ? tokens.segmented.itemActive
                      : tokens.segmented.itemInactive,
                  )}
                >
                  <List className="h-4 w-4" />
                  {t('settings.listeMode')}
                </button>
                <button
                  onClick={() => setDisplayMode('tableau')}
                  className={cn(
                    tokens.segmented.item,
                    'flex flex-1 items-center justify-center gap-2',
                    displayMode === 'tableau'
                      ? tokens.segmented.itemActive
                      : tokens.segmented.itemInactive,
                  )}
                >
                  <Table className="h-4 w-4" />
                  {t('settings.tableauMode')}
                </button>
              </div>
            </div>

            {/* Language */}
            <div>
              <label htmlFor="language-select" className="mb-2 block text-sm font-medium text-ink-muted">
                {t('settings.language')}
              </label>
              <div className="relative">
                <Globe className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-faint" />
                <select
                  id="language-select"
                  value={language}
                  onChange={(e) => setLanguage(e.target.value)}
                  className="min-h-[44px] w-full appearance-none rounded-ctl border border-border-strong bg-surface-raised py-2.5 pl-10 pr-8 text-base font-medium text-ink focus:border-action focus:outline-none focus:ring-2 focus:ring-action"
                >
                  {SUPPORTED_LANGUAGES.map((lang) => (
                    <option key={lang.code} value={lang.code}>
                      {lang.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* Optional-field toggles — Task 16, default off */}
            <div className="mt-4">
              <label className="mb-2 block text-sm font-medium text-ink-muted">
                {t('settings.displayFields')}
              </label>
              <div className="space-y-3">
                <div className="flex items-center justify-between gap-3 rounded-tile bg-surface-sunken px-3 py-3">
                  <div className="flex min-w-0 items-center gap-2">
                    <Tag className="h-4 w-4 shrink-0 text-ink-muted" />
                    <div className="min-w-0">
                      <span className="text-sm font-medium text-ink">
                        {t('settings.showSkuOnRows')}
                      </span>
                      <p className="text-xs text-ink-faint">
                        {t('settings.showSkuOnRowsDesc')}
                      </p>
                    </div>
                  </div>
                  <ToggleSwitch
                    checked={showSkuOnRows}
                    onChange={() => setShowSkuOnRows(!showSkuOnRows)}
                  />
                </div>

                <div className="flex items-center justify-between gap-3 rounded-tile bg-surface-sunken px-3 py-3">
                  <div className="flex min-w-0 items-center gap-2">
                    <Droplet className="h-4 w-4 shrink-0 text-ink-muted" />
                    <div className="min-w-0">
                      <span className="text-sm font-medium text-ink">
                        {t('settings.showSkinTypeOnTiles')}
                      </span>
                      <p className="text-xs text-ink-faint">
                        {t('settings.showSkinTypeOnTilesDesc')}
                      </p>
                    </div>
                  </div>
                  <ToggleSwitch
                    checked={showSkinTypeOnTiles}
                    onChange={() => setShowSkinTypeOnTiles(!showSkinTypeOnTiles)}
                  />
                </div>
              </div>
            </div>
          </section>

          {/* Appearance — theme foundation knobs */}
          <section id="settings-appearance" className="rounded-card bg-surface-raised p-4 shadow-sm">
            <h2 className="mb-1 text-base font-bold text-ink">
              {t('settings.appearance')}
            </h2>
            <p className="mb-4 text-sm text-ink-muted">{t('settings.appearanceDesc')}</p>

            {/* Theme */}
            <div className="mb-4">
              <label className="mb-2 block text-sm font-medium text-ink-muted">
                {t('settings.theme')}
              </label>
              <SegmentedControl
                className="flex w-full"
                ariaLabel={t('settings.theme')}
                value={theme}
                onChange={setTheme}
                options={[
                  { value: 'light', label: t('settings.themeLight'), icon: <Sun className="h-4 w-4" /> },
                  { value: 'dark', label: t('settings.themeDark'), icon: <Moon className="h-4 w-4" /> },
                ]}
              />
            </div>

            {/* Accent */}
            <div className="mb-4">
              <label className="mb-2 block text-sm font-medium text-ink-muted">
                {t('settings.accent')}
              </label>
              <div className="flex gap-3">
                {ACCENTS.map((name) => {
                  const active = accent === name;
                  return (
                    <button
                      key={name}
                      type="button"
                      onClick={() => setAccent(name as AccentName)}
                      aria-pressed={active}
                      aria-label={t(`settings.accent${name.charAt(0).toUpperCase()}${name.slice(1)}`)}
                      title={t(`settings.accent${name.charAt(0).toUpperCase()}${name.slice(1)}`)}
                      className={cn(
                        'h-11 w-11 rounded-full border-2 transition-transform',
                        active
                          ? 'border-ink scale-110 shadow-sm'
                          : 'border-border-subtle hover:scale-105',
                      )}
                      style={{ backgroundColor: ACCENT_SWATCH[name] }}
                    />
                  );
                })}
              </div>
            </div>

            {/* Corners */}
            <div className="mb-4">
              <label className="mb-2 block text-sm font-medium text-ink-muted">
                {t('settings.corner')}
              </label>
              <SegmentedControl
                className="flex w-full"
                ariaLabel={t('settings.corner')}
                value={corner}
                onChange={setCorner}
                options={[
                  { value: 'rounded', label: t('settings.cornerRounded') },
                  { value: 'sharp', label: t('settings.cornerSharp') },
                ]}
              />
            </div>

            {/* Grid density */}
            <div>
              <label className="mb-2 block text-sm font-medium text-ink-muted">
                {t('settings.density')}
              </label>
              <SegmentedControl
                className="flex w-full"
                ariaLabel={t('settings.density')}
                value={density}
                onChange={setDensity}
                options={[
                  { value: 'comfortable', label: t('settings.densityComfortable') },
                  { value: 'dense', label: t('settings.densityDense') },
                ]}
              />
            </div>
          </section>

          {/* Touch & Display */}
          <section id="settings-touch" className="rounded-card bg-surface-raised p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-ink">
              {t('settings.touchDisplay')}
            </h2>
            <div className="space-y-3">
              {/* Touch mode toggle */}
              <div className="flex items-center justify-between rounded-tile bg-surface-sunken px-3 py-3">
                <div className="flex items-center gap-2">
                  <Hand className="h-4 w-4 text-ink-muted" />
                  <div>
                    <span className="text-sm font-medium text-ink">
                      {t('settings.touchMode')}
                    </span>
                    <p className="text-xs text-ink-faint">
                      {t('settings.touchModeDesc')}
                    </p>
                  </div>
                </div>
                <ToggleSwitch
                  checked={touchMode}
                  onChange={() => setTouchMode(!touchMode)}
                />
              </div>

              {/* Fullscreen toggle */}
              <div className="flex items-center justify-between rounded-tile bg-surface-sunken px-3 py-3">
                <div className="flex items-center gap-2">
                  <Maximize className="h-4 w-4 text-ink-muted" />
                  <div>
                    <span className="text-sm font-medium text-ink">
                      {t('settings.fullscreen')}
                    </span>
                    <p className="text-xs text-ink-faint">
                      {t('settings.fullscreenDesc')}
                    </p>
                  </div>
                </div>
                <ToggleSwitch
                  checked={fullscreen}
                  onChange={() => {
                    const next = !fullscreen;
                    setFullscreen(next);
                    void applyFullscreen(next);
                  }}
                />
              </div>

              {/* Force Fullscreen — manual retry / escape hatch for BG9 */}
              <div className="flex items-center justify-between gap-3 rounded-tile bg-surface-sunken px-3 py-3">
                <div className="flex min-w-0 items-center gap-2">
                  <Maximize className="h-4 w-4 shrink-0 text-ink-muted" />
                  <div className="min-w-0">
                    <span className="text-sm font-medium text-ink">
                      {t('settings.forceFullscreen')}
                    </span>
                    <p className="text-xs text-ink-faint">
                      {forceFullscreenApplied
                        ? t('settings.forceFullscreenApplied')
                        : t('settings.forceFullscreenDesc')}
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={async () => {
                    await applyFullscreen(true);
                    useSettingsStore.getState().setFullscreen(true);
                    setForceFullscreenApplied(true);
                    setTimeout(() => { setForceFullscreenApplied(false); }, 2_000);
                  }}
                  className={cn(tokens.button.primary, 'h-9 shrink-0 whitespace-nowrap px-3 text-sm')}
                >
                  {t('settings.forceFullscreen')}
                </button>
              </div>

              {/* Confirm before removing a cart line (mis-tap guard) */}
              <div className="flex items-center justify-between gap-3 rounded-tile bg-surface-sunken px-3 py-3">
                <div className="flex min-w-0 items-center gap-2">
                  <Trash2 className="h-4 w-4 shrink-0 text-ink-muted" />
                  <div className="min-w-0">
                    <span className="text-sm font-medium text-ink">
                      {t('settings.confirmLineDelete')}
                    </span>
                    <p className="text-xs text-ink-faint">
                      {t('settings.confirmLineDeleteDesc')}
                    </p>
                  </div>
                </div>
                <ToggleSwitch
                  checked={confirmLineDelete}
                  onChange={() => setConfirmLineDelete(!confirmLineDelete)}
                />
              </div>

              <div className="flex items-center justify-between gap-3 rounded-tile bg-surface-sunken px-3 py-3">
                <div className="flex min-w-0 items-center gap-2">
                  <SlidersHorizontal className="h-4 w-4 shrink-0 text-ink-muted" />
                  <div className="min-w-0">
                    <span className="text-sm font-medium text-ink">
                      {t('settings.parapharmacySkinFilters')}
                    </span>
                    <p className="text-xs text-ink-faint">
                      {t('settings.parapharmacySkinFiltersDesc')}
                    </p>
                  </div>
                </div>
                <ToggleSwitch
                  checked={parapharmacySkinFiltersEnabled}
                  onChange={() => setParapharmacySkinFiltersEnabled(!parapharmacySkinFiltersEnabled)}
                />
              </div>
            </div>
          </section>

          {/* Security */}
          <section id="settings-security" className="rounded-card bg-surface-raised p-4 shadow-sm">
            <div className="mb-4 flex items-center gap-2">
              <Shield className="h-5 w-5 text-ink-muted" />
              <h2 className="text-base font-bold text-ink">
                {t('settings.security')}
              </h2>
            </div>

            <div className="space-y-4">
              {/* Inactivity timeout */}
              <div>
                <label className="mb-1 block text-sm font-medium text-ink">
                  {t('settings.inactivityTimeout')}
                </label>
                <p className="mb-2 text-xs text-ink-faint">
                  {t('settings.inactivityTimeoutDesc')}
                </p>
                <div className={cn(tokens.segmented.root, 'flex flex-wrap')}>
                  {TIMEOUT_PRESETS.map((preset) => (
                    <button
                      key={preset.value}
                      onClick={() => setInactivityTimeout(preset.value)}
                      className={cn(
                        tokens.segmented.item,
                        inactivityTimeout === preset.value
                          ? tokens.segmented.itemActive
                          : tokens.segmented.itemInactive,
                      )}
                    >
                      {t(preset.labelKey)}
                    </button>
                  ))}
                </div>
              </div>

              {/* Lock after each sale */}
              <div className="flex items-center justify-between rounded-tile bg-surface-sunken px-3 py-3">
                <div>
                  <span className="text-sm font-medium text-ink">
                    {t('settings.lockAfterSale')}
                  </span>
                  <p className="text-xs text-ink-faint">
                    {t('settings.lockAfterSaleDesc')}
                  </p>
                </div>
                <ToggleSwitch
                  checked={lockAfterSale}
                  onChange={() => setLockAfterSale(!lockAfterSale)}
                />
              </div>
            </div>
          </section>

          {/* Receipt Printer */}
          <section id="settings-printer" className="rounded-card bg-surface-raised p-4 shadow-sm">
            <div className="mb-4 flex items-center gap-2">
              <Printer className="h-5 w-5 text-ink-muted" />
              <h2 className="text-base font-bold text-ink">
                {t('settings.printer')}
              </h2>
            </div>

            {!isTauri ? (
              <div className="rounded-tile border border-warning-subtle bg-warning-surface p-3 text-sm text-warning-strong">
                {t('settings.printerDesktopOnly')}
              </div>
            ) : (
              <div className="space-y-4">
                {/* Current printer */}
                {printerConfig ? (
                  <div className="rounded-tile border border-success-subtle bg-success-surface p-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium text-success-strong">
                          {printerConfig.name}
                        </p>
                        <p className="text-xs text-success-strong">
                          {printerConfig.connection_type === 'usb'
                          ? 'USB'
                          : printerConfig.connection_type === 'windows'
                            ? t('settings.windowsPrinter')
                            : t('settings.networkPrinter')} — {printerConfig.address}
                        </p>
                      </div>
                      <button
                        onClick={clearPrinterConfig}
                        className="rounded-ctl px-2 py-1 text-xs font-medium text-danger hover:bg-danger-surface"
                      >
                        {t('settings.removePrinter')}
                      </button>
                    </div>

                    {/* Actions for configured printer */}
                    <div className="mt-3 flex gap-2">
                      <button
                        onClick={() => void handleTestPrint()}
                        disabled={isPrintingTest}
                        className="flex items-center gap-1 rounded-ctl bg-surface-raised px-3 py-2 text-xs font-medium text-ink-muted shadow-sm hover:bg-surface-sunken disabled:opacity-50"
                      >
                        {isPrintingTest ? (
                          <Loader2 className="h-3 w-3 animate-spin" />
                        ) : (
                          <Printer className="h-3 w-3" />
                        )}
                        {t('settings.testPrint')}
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="rounded-tile bg-surface-sunken p-3 text-center text-sm text-ink-faint">
                    {t('settings.noPrinterConfigured')}
                  </div>
                )}

                {/* Auto-print toggle */}
                <div className="flex items-center justify-between rounded-tile bg-surface-sunken px-3 py-3">
                  <span className="text-sm font-medium text-ink">
                    {t('settings.autoPrintReceipts')}
                  </span>
                  <ToggleSwitch
                    checked={autoPrint}
                    onChange={() => setAutoPrint(!autoPrint)}
                  />
                </div>

                {/* Advanced printer settings */}
                <PrinterAdvancedSettings />

                {/* Discover printers */}
                <button
                  onClick={() => void handleDiscoverPrinters()}
                  disabled={isDiscovering}
                  className="flex w-full items-center justify-center gap-2 rounded-ctl border border-border-strong bg-surface-raised px-4 py-3 text-sm font-medium text-ink-muted hover:bg-surface-sunken disabled:opacity-50"
                >
                  {isDiscovering ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Search className="h-4 w-4" />
                  )}
                  {isDiscovering
                    ? t('settings.scanning')
                    : t('settings.scanForPrinters')}
                </button>

                {/* Discovered printers list */}
                {discoveredPrinters.length > 0 && (
                  <div className="space-y-2">
                    <p className="text-xs font-medium uppercase text-ink-faint">
                      {t('settings.availablePrinters')}
                    </p>
                    {discoveredPrinters.map((printer) => (
                      <button
                        key={printer.id}
                        onClick={() => handleSelectPrinter(printer)}
                        className={cn(
                          'flex w-full items-center justify-between rounded-tile border px-3 py-3 text-left transition-colors',
                          printerConfig?.address === printer.address
                            ? 'border-action bg-action-subtle'
                            : 'border-border-subtle bg-surface-raised hover:bg-surface-sunken',
                        )}
                      >
                        <div>
                          <p className="text-sm font-medium text-ink">
                            {printer.name}
                          </p>
                          <p className="text-xs text-ink-faint">
                            {printer.connection_type === 'usb'
                            ? 'USB'
                            : printer.connection_type === 'windows'
                              ? t('settings.windowsPrinter')
                              : t('settings.networkPrinter')} — {printer.address}
                          </p>
                        </div>
                        {printerConfig?.address === printer.address && (
                          <CheckCircle className="h-5 w-5 text-action" />
                        )}
                      </button>
                    ))}
                  </div>
                )}

                {/* Manual network printer entry */}
                <ManualPrinterEntry onSelect={handleSelectPrinter} />

                {/* Status message */}
                {printerStatus !== 'idle' && printerMessage && (
                  <div
                    className={cn(
                      'flex items-center gap-2 rounded-tile p-3 text-sm',
                      printerStatus === 'success'
                        ? 'bg-success-surface text-success-strong'
                        : 'bg-danger-surface text-danger-strong',
                    )}
                  >
                    {printerStatus === 'success' ? (
                      <CheckCircle className="h-4 w-4 flex-shrink-0" />
                    ) : (
                      <AlertCircle className="h-4 w-4 flex-shrink-0" />
                    )}
                    {printerMessage}
                  </div>
                )}
              </div>
            )}
          </section>

          {/* Customer-Facing Display */}
          <CustomerDisplaySettings />

          {/* Cash Drawer Settings */}
          <CashDrawerSettings />

          {/* Barcode Scanner Settings */}
          <ScannerSettings />

          {/* Kitchen Printer (coming soon) */}
          <section id="settings-kitchen" className="rounded-card bg-surface-raised p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-ink">
              {t('settings.kitchenPrinter')}
            </h2>
            <div className="flex items-center justify-between rounded-tile bg-surface-sunken px-3 py-3">
              <span className="text-sm font-medium text-ink">{t('settings.kitchenPrinter')}</span>
              <span className="text-xs text-ink-faint">{t('settings.comingSoon')}</span>
            </div>
          </section>

          {/* Terminal Info */}
          <section id="settings-terminal" className="rounded-card bg-surface-raised p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-ink">
              {t('settings.terminal')}
            </h2>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-ink-faint">{t('terminal.terminalName')}</span>
                <span className="font-medium text-ink">
                  {terminal?.name ?? '-'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-ink-faint">{t('terminal.location')}</span>
                <span className="font-medium text-ink">
                  {terminal?.location.name ?? '-'}
                </span>
              </div>
              {shift && (
                <div className="flex justify-between">
                  <span className="text-ink-faint">
                    {t('shift.number', { number: shift.shift_number })}
                  </span>
                  <span className="font-medium text-ink">{t('shift.statusLabel.' + shift.status)}</span>
                </div>
              )}
            </div>
          </section>

          {/* Device & Security — manager only */}
          {isManager && (
            <section id="settings-device-security" data-testid="device-security-section" className="rounded-card bg-surface-raised p-4 shadow-sm">
              <h2 className="mb-4 text-base font-bold text-ink">{t('settings.deviceSecurity')}</h2>
              {terminal && (
                <div className="mb-3">
                  <p className="mb-2 text-xs text-ink-faint">{t('terminal.changeTerminalDesc')}</p>
                  <button
                    type="button"
                    onClick={() => {
                      if (window.confirm(t('terminal.changeTerminalConfirm'))) {
                        // Task 7 (audit): pos.terminal_change. Capture the
                        // previous terminal id BEFORE reset() clears it; emit
                        // fire-and-forget so the change is never blocked.
                        const previousTerminalId =
                          useTerminalStore.getState().terminal?.id ?? null;
                        useTerminalStore.getState().reset();
                        if (previousTerminalId) {
                          void recordAuditEvent({
                            type: 'pos.terminal_change',
                            aggregateType: 'Terminal',
                            aggregateId: previousTerminalId,
                            payload: { previous_terminal_id: previousTerminalId },
                          }).catch(() => {});
                        }
                        navigate('/');
                      }
                    }}
                    className="flex w-full items-center justify-center gap-2 rounded-ctl border border-warning-subtle bg-warning-surface px-4 py-3 text-sm font-medium text-warning-strong hover:opacity-90"
                  >
                    <RefreshCw className="h-4 w-4" />{t('terminal.changeTerminal')}
                  </button>
                </div>
              )}
              <button
                type="button"
                data-testid="device-unbind-button"
                onClick={() => setShowUnbindConfirm(true)}
                className="flex w-full items-center justify-center gap-2 rounded-ctl border border-danger-subtle bg-danger-surface px-4 py-3 text-sm font-medium text-danger-strong hover:opacity-90"
              >
                <LogOut className="h-4 w-4" />{t('settings.deviceUnbind')}
              </button>
            </section>
          )}

          {/* About */}
          <section id="settings-about" className="rounded-card bg-surface-raised p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-ink">
              {t('settings.about')}
            </h2>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-ink-faint">{t('settings.version')}</span>
                <span className="font-medium text-ink">1.0.0</span>
              </div>
              <div className="flex justify-between">
                <span className="text-ink-faint">{t('settings.serverUrl')}</span>
                <span className="max-w-[200px] truncate font-medium text-ink">
                  {serverUrl ?? '-'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-ink-faint">{t('settings.syncStatus')}</span>
                <span className={isOnline ? tokens.badge.success : tokens.badge.danger}>
                  {isOnline ? t('sync.online') : t('sync.offline')}
                </span>
              </div>
            </div>
          </section>
          </div>
        </div>
      </div>

      {/* Device Unbind Confirmation Modal — FIX 2: only rendered for managers */}
      {isManager && showUnbindConfirm && (
        <Modal
          isOpen={showUnbindConfirm}
          onClose={() => setShowUnbindConfirm(false)}
          title={t('settings.deviceUnbindConfirmTitle')}
          size="sm"
          footer={
            <div className="flex gap-3">
              <button
                type="button"
                data-testid="device-unbind-cancel"
                onClick={() => setShowUnbindConfirm(false)}
                className={cn(tokens.button.secondary, 'flex-1 py-2.5 text-sm')}
              >
                {t('settings.cancel')}
              </button>
              <button
                type="button"
                data-testid="device-unbind-confirm"
                onClick={() => void handleConfirmUnbind()}
                className={cn(tokens.button.destructive, 'flex-1 py-2.5 text-sm')}
              >
                {t('settings.deviceUnbindConfirm')}
              </button>
            </div>
          }
        >
          <p className="text-sm text-ink-muted">{t('settings.deviceUnbindConfirmMessage')}</p>
        </Modal>
      )}
    </div>
  );
}

/** Sub-component for manually entering a network printer address. */
function ManualPrinterEntry({
  onSelect,
}: {
  onSelect: (printer: PrinterInfo) => void;
}) {
  const { t } = useTranslation('pos');
  const [expanded, setExpanded] = useState(false);
  const [ipAddress, setIpAddress] = useState('');
  const [port, setPort] = useState('9100');

  const handleAdd = () => {
    const trimmedIp = ipAddress.trim();
    if (!trimmedIp) return;
    const address = `${trimmedIp}:${port || '9100'}`;
    onSelect({
      id: `net:${trimmedIp}`,
      name: `${t('settings.networkPrinter')} (${trimmedIp})`,
      connection_type: 'network',
      address,
    });
    setIpAddress('');
    setExpanded(false);
  };

  if (!expanded) {
    return (
      <button
        onClick={() => setExpanded(true)}
        className="w-full text-center text-sm font-medium text-action hover:text-action-strong"
      >
        {t('settings.addManualPrinter')}
      </button>
    );
  }

  return (
    <div className="rounded-tile border border-border-subtle p-3">
      <p className="mb-2 text-xs font-medium uppercase text-ink-faint">
        {t('settings.manualNetworkPrinter')}
      </p>
      <div className="flex gap-2">
        <input
          type="text"
          value={ipAddress}
          onChange={(e) => setIpAddress(e.target.value)}
          placeholder={t('settings.ipAddressPlaceholder')}
          className="min-h-[44px] flex-1 rounded-ctl border border-border-strong px-3 text-sm focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
        />
        <input
          type="text"
          value={port}
          onChange={(e) => setPort(e.target.value)}
          placeholder="9100"
          className="min-h-[44px] w-20 rounded-ctl border border-border-strong px-3 text-sm focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
        />
      </div>
      <div className="mt-2 flex gap-2">
        <button
          onClick={handleAdd}
          disabled={!ipAddress.trim()}
          className={cn(tokens.button.primary, 'flex-1 py-2 text-sm disabled:opacity-50')}
        >
          {t('settings.addPrinter')}
        </button>
        <button
          onClick={() => setExpanded(false)}
          className={cn(tokens.button.secondary, 'py-2 text-sm')}
        >
          {t('settings.cancel')}
        </button>
      </div>
    </div>
  );
}

/**
 * Tokenized toggle switch — the ONE switch voice across Settings.
 * Active = `bg-action`, inactive = `bg-border-strong`, knob = `bg-surface-raised`.
 */
function ToggleSwitch({
  checked,
  onChange,
}: {
  checked: boolean;
  onChange: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onChange}
      role="switch"
      aria-checked={checked}
      className={cn(
        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-pill transition-colors',
        checked ? 'bg-action' : 'bg-border-strong',
      )}
    >
      <span
        className={cn(
          'inline-block h-4 w-4 rounded-full bg-surface-raised transition-transform',
          checked ? 'translate-x-6' : 'translate-x-1',
        )}
      />
    </button>
  );
}
