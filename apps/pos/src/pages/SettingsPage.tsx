import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Monitor, Image, Globe, Printer, Search, CheckCircle, AlertCircle, Loader2, Hand, Maximize, Shield, RefreshCw, LogOut } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useSettingsStore, SUPPORTED_LANGUAGES } from '@/stores/settingsStore';
import { usePrinterStore } from '@/stores/printerStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { Modal } from '@/components/pos/Modal';
import { teardownPosSessionStores } from '@/lib/session/teardownPosSession';
import { isManagerRole } from '@/lib/auth/roles';
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
  const inactivityTimeout = useSettingsStore((s) => s.inactivityTimeout);
  const lockAfterSale = useSettingsStore((s) => s.lockAfterSale);
  const setInactivityTimeout = useSettingsStore((s) => s.setInactivityTimeout);
  const setLockAfterSale = useSettingsStore((s) => s.setLockAfterSale);

  const terminal = useTerminalStore((s) => s.terminal);
  const shift = useTerminalStore((s) => s.shift);

  const serverUrl = useAuthStore((s) => s.serverUrl);
  const unbindDevice = useAuthStore((s) => s.unbindDevice);

  const operator = useOperatorStore((s) => s.operator);
  const isManager = isManagerRole(operator?.roles);

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

  return (
    <div className="flex h-full flex-col bg-gray-50">
      {/* Header */}
      <div className="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
        <button
          onClick={() => navigate('/')}
          className="flex h-10 w-10 items-center justify-center rounded-full text-gray-600 hover:bg-gray-100"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
        <h1 className="text-xl font-bold text-gray-900">{t('settings.title')}</h1>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-y-auto p-4">
        <div className="mx-auto max-w-lg space-y-6">
          {/* Display Preferences */}
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-gray-900">
              {t('settings.display')}
            </h2>

            {/* Display mode */}
            <div className="mb-4">
              <label className="mb-2 block text-sm font-medium text-gray-700">
                {t('settings.displayMode')}
              </label>
              <div className="flex rounded-lg bg-gray-100 p-1">
                <button
                  onClick={() => setDisplayMode('grid')}
                  className={cn(
                    'flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    displayMode === 'grid'
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-500 hover:text-gray-700',
                  )}
                >
                  <Monitor className="h-4 w-4" />
                  {t('settings.gridMode')}
                </button>
                <button
                  onClick={() => setDisplayMode('visual')}
                  className={cn(
                    'flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    displayMode === 'visual'
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-500 hover:text-gray-700',
                  )}
                >
                  <Image className="h-4 w-4" />
                  {t('settings.visualMode')}
                </button>
              </div>
            </div>

            {/* Language */}
            <div>
              <label htmlFor="language-select" className="mb-2 block text-sm font-medium text-gray-700">
                {t('settings.language')}
              </label>
              <div className="relative">
                <Globe className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                <select
                  id="language-select"
                  value={language}
                  onChange={(e) => setLanguage(e.target.value)}
                  className="min-h-[44px] w-full appearance-none rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-8 text-base font-medium text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
                >
                  {SUPPORTED_LANGUAGES.map((lang) => (
                    <option key={lang.code} value={lang.code}>
                      {lang.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </section>

          {/* Touch & Display */}
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-gray-900">
              {t('settings.touchDisplay')}
            </h2>
            <div className="space-y-3">
              {/* Touch mode toggle */}
              <div className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3">
                <div className="flex items-center gap-2">
                  <Hand className="h-4 w-4 text-gray-600" />
                  <div>
                    <span className="text-sm font-medium text-gray-900">
                      {t('settings.touchMode')}
                    </span>
                    <p className="text-xs text-gray-500">
                      {t('settings.touchModeDesc')}
                    </p>
                  </div>
                </div>
                <button
                  onClick={() => setTouchMode(!touchMode)}
                  className={cn(
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    touchMode ? 'bg-blue-600' : 'bg-gray-300',
                  )}
                  role="switch"
                  aria-checked={touchMode}
                >
                  <span
                    className={cn(
                      'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                      touchMode ? 'translate-x-6' : 'translate-x-1',
                    )}
                  />
                </button>
              </div>

              {/* Fullscreen toggle */}
              <div className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3">
                <div className="flex items-center gap-2">
                  <Maximize className="h-4 w-4 text-gray-600" />
                  <div>
                    <span className="text-sm font-medium text-gray-900">
                      {t('settings.fullscreen')}
                    </span>
                    <p className="text-xs text-gray-500">
                      {t('settings.fullscreenDesc')}
                    </p>
                  </div>
                </div>
                <button
                  onClick={() => {
                    const next = !fullscreen;
                    setFullscreen(next);
                    void applyFullscreen(next);
                  }}
                  className={cn(
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    fullscreen ? 'bg-blue-600' : 'bg-gray-300',
                  )}
                  role="switch"
                  aria-checked={fullscreen}
                >
                  <span
                    className={cn(
                      'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                      fullscreen ? 'translate-x-6' : 'translate-x-1',
                    )}
                  />
                </button>
              </div>

              {/* Force Fullscreen — manual retry / escape hatch for BG9 */}
              <div className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3">
                <div className="flex items-center gap-2">
                  <Maximize className="h-4 w-4 text-gray-600" />
                  <div>
                    <span className="text-sm font-medium text-gray-900">
                      {t('settings.forceFullscreen')}
                    </span>
                    <p className="text-xs text-gray-500">
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
                  className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                >
                  {t('settings.forceFullscreen')}
                </button>
              </div>
            </div>
          </section>

          {/* Security */}
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <div className="mb-4 flex items-center gap-2">
              <Shield className="h-5 w-5 text-gray-700" />
              <h2 className="text-base font-bold text-gray-900">
                {t('settings.security')}
              </h2>
            </div>

            <div className="space-y-4">
              {/* Inactivity timeout */}
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-900">
                  {t('settings.inactivityTimeout')}
                </label>
                <p className="mb-2 text-xs text-gray-500">
                  {t('settings.inactivityTimeoutDesc')}
                </p>
                <div className="flex flex-wrap gap-2">
                  {TIMEOUT_PRESETS.map((preset) => (
                    <button
                      key={preset.value}
                      onClick={() => setInactivityTimeout(preset.value)}
                      className={cn(
                        'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                        inactivityTimeout === preset.value
                          ? 'bg-blue-600 text-white'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
                      )}
                    >
                      {t(preset.labelKey)}
                    </button>
                  ))}
                </div>
              </div>

              {/* Lock after each sale */}
              <div className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3">
                <div>
                  <span className="text-sm font-medium text-gray-900">
                    {t('settings.lockAfterSale')}
                  </span>
                  <p className="text-xs text-gray-500">
                    {t('settings.lockAfterSaleDesc')}
                  </p>
                </div>
                <button
                  onClick={() => setLockAfterSale(!lockAfterSale)}
                  className={cn(
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    lockAfterSale ? 'bg-blue-600' : 'bg-gray-300',
                  )}
                  role="switch"
                  aria-checked={lockAfterSale}
                >
                  <span
                    className={cn(
                      'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                      lockAfterSale ? 'translate-x-6' : 'translate-x-1',
                    )}
                  />
                </button>
              </div>
            </div>
          </section>

          {/* Receipt Printer */}
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <div className="mb-4 flex items-center gap-2">
              <Printer className="h-5 w-5 text-gray-700" />
              <h2 className="text-base font-bold text-gray-900">
                {t('settings.printer')}
              </h2>
            </div>

            {!isTauri ? (
              <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-700">
                {t('settings.printerDesktopOnly')}
              </div>
            ) : (
              <div className="space-y-4">
                {/* Current printer */}
                {printerConfig ? (
                  <div className="rounded-lg border border-green-200 bg-green-50 p-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium text-green-800">
                          {printerConfig.name}
                        </p>
                        <p className="text-xs text-green-600">
                          {printerConfig.connection_type === 'usb'
                          ? 'USB'
                          : printerConfig.connection_type === 'windows'
                            ? t('settings.windowsPrinter')
                            : t('settings.networkPrinter')} — {printerConfig.address}
                        </p>
                      </div>
                      <button
                        onClick={clearPrinterConfig}
                        className="rounded-md px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
                      >
                        {t('settings.removePrinter')}
                      </button>
                    </div>

                    {/* Actions for configured printer */}
                    <div className="mt-3 flex gap-2">
                      <button
                        onClick={() => void handleTestPrint()}
                        disabled={isPrintingTest}
                        className="flex items-center gap-1 rounded-lg bg-white px-3 py-2 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
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
                  <div className="rounded-lg bg-gray-50 p-3 text-center text-sm text-gray-500">
                    {t('settings.noPrinterConfigured')}
                  </div>
                )}

                {/* Auto-print toggle */}
                <div className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3">
                  <span className="text-sm font-medium text-gray-900">
                    {t('settings.autoPrintReceipts')}
                  </span>
                  <button
                    onClick={() => setAutoPrint(!autoPrint)}
                    className={cn(
                      'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                      autoPrint ? 'bg-blue-600' : 'bg-gray-300',
                    )}
                    role="switch"
                    aria-checked={autoPrint}
                  >
                    <span
                      className={cn(
                        'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                        autoPrint ? 'translate-x-6' : 'translate-x-1',
                      )}
                    />
                  </button>
                </div>

                {/* Advanced printer settings */}
                <PrinterAdvancedSettings />

                {/* Discover printers */}
                <button
                  onClick={() => void handleDiscoverPrinters()}
                  disabled={isDiscovering}
                  className="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
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
                    <p className="text-xs font-medium uppercase text-gray-500">
                      {t('settings.availablePrinters')}
                    </p>
                    {discoveredPrinters.map((printer) => (
                      <button
                        key={printer.id}
                        onClick={() => handleSelectPrinter(printer)}
                        className={cn(
                          'flex w-full items-center justify-between rounded-lg border px-3 py-3 text-left transition-colors',
                          printerConfig?.address === printer.address
                            ? 'border-blue-300 bg-blue-50'
                            : 'border-gray-200 bg-white hover:bg-gray-50',
                        )}
                      >
                        <div>
                          <p className="text-sm font-medium text-gray-900">
                            {printer.name}
                          </p>
                          <p className="text-xs text-gray-500">
                            {printer.connection_type === 'usb'
                            ? 'USB'
                            : printer.connection_type === 'windows'
                              ? t('settings.windowsPrinter')
                              : t('settings.networkPrinter')} — {printer.address}
                          </p>
                        </div>
                        {printerConfig?.address === printer.address && (
                          <CheckCircle className="h-5 w-5 text-blue-600" />
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
                      'flex items-center gap-2 rounded-lg p-3 text-sm',
                      printerStatus === 'success'
                        ? 'bg-green-50 text-green-700'
                        : 'bg-red-50 text-red-700',
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
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-gray-900">
              {t('settings.kitchenPrinter')}
            </h2>
            <div className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3">
              <span className="text-sm font-medium text-gray-900">{t('settings.kitchenPrinter')}</span>
              <span className="text-xs text-gray-400">{t('settings.comingSoon')}</span>
            </div>
          </section>

          {/* Terminal Info */}
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-gray-900">
              {t('settings.terminal')}
            </h2>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-500">{t('terminal.terminalName')}</span>
                <span className="font-medium text-gray-900">
                  {terminal?.name ?? '-'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('terminal.location')}</span>
                <span className="font-medium text-gray-900">
                  {terminal?.location.name ?? '-'}
                </span>
              </div>
              {shift && (
                <div className="flex justify-between">
                  <span className="text-gray-500">
                    {t('shift.number', { number: shift.shift_number })}
                  </span>
                  <span className="font-medium text-gray-900">{t('shift.statusLabel.' + shift.status)}</span>
                </div>
              )}
            </div>
          </section>

          {/* Device & Security — manager only */}
          {isManager && (
            <section data-testid="device-security-section" className="rounded-xl bg-white p-4 shadow-sm">
              <h2 className="mb-4 text-base font-bold text-gray-900">{t('settings.deviceSecurity')}</h2>
              {terminal && (
                <div className="mb-3">
                  <p className="mb-2 text-xs text-gray-500">{t('terminal.changeTerminalDesc')}</p>
                  <button
                    type="button"
                    onClick={() => {
                      if (window.confirm(t('terminal.changeTerminalConfirm'))) {
                        useTerminalStore.getState().reset();
                        navigate('/');
                      }
                    }}
                    className="flex w-full items-center justify-center gap-2 rounded-lg border border-orange-300 bg-orange-50 px-4 py-3 text-sm font-medium text-orange-700 hover:bg-orange-100"
                  >
                    <RefreshCw className="h-4 w-4" />{t('terminal.changeTerminal')}
                  </button>
                </div>
              )}
              <button
                type="button"
                data-testid="device-unbind-button"
                onClick={() => setShowUnbindConfirm(true)}
                className="flex w-full items-center justify-center gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 hover:bg-red-100"
              >
                <LogOut className="h-4 w-4" />{t('settings.deviceUnbind')}
              </button>
            </section>
          )}

          {/* About */}
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-gray-900">
              {t('settings.about')}
            </h2>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-500">{t('settings.version')}</span>
                <span className="font-medium text-gray-900">1.0.0</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('settings.serverUrl')}</span>
                <span className="max-w-[200px] truncate font-medium text-gray-900">
                  {serverUrl ?? '-'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('settings.syncStatus')}</span>
                <span
                  className={cn(
                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                    isOnline
                      ? 'bg-green-50 text-green-600'
                      : 'bg-red-50 text-red-600',
                  )}
                >
                  {isOnline ? t('sync.online') : t('sync.offline')}
                </span>
              </div>
            </div>
          </section>

          {/* Back button */}
          <button
            onClick={() => navigate('/')}
            className="w-full rounded-xl bg-gray-200 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-300"
          >
            {t('settings.back')}
          </button>
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
                className="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('settings.cancel')}
              </button>
              <button
                type="button"
                data-testid="device-unbind-confirm"
                onClick={() => void handleConfirmUnbind()}
                className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700"
              >
                {t('settings.deviceUnbindConfirm')}
              </button>
            </div>
          }
        >
          <p className="text-sm text-gray-600">{t('settings.deviceUnbindConfirmMessage')}</p>
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
        className="w-full text-center text-sm font-medium text-blue-600 hover:text-blue-700"
      >
        {t('settings.addManualPrinter')}
      </button>
    );
  }

  return (
    <div className="rounded-lg border border-gray-200 p-3">
      <p className="mb-2 text-xs font-medium uppercase text-gray-500">
        {t('settings.manualNetworkPrinter')}
      </p>
      <div className="flex gap-2">
        <input
          type="text"
          value={ipAddress}
          onChange={(e) => setIpAddress(e.target.value)}
          placeholder={t('settings.ipAddressPlaceholder')}
          className="min-h-[44px] flex-1 rounded-lg border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        />
        <input
          type="text"
          value={port}
          onChange={(e) => setPort(e.target.value)}
          placeholder="9100"
          className="min-h-[44px] w-20 rounded-lg border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        />
      </div>
      <div className="mt-2 flex gap-2">
        <button
          onClick={handleAdd}
          disabled={!ipAddress.trim()}
          className="flex-1 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {t('settings.addPrinter')}
        </button>
        <button
          onClick={() => setExpanded(false)}
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          {t('settings.cancel')}
        </button>
      </div>
    </div>
  );
}
