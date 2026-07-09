import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Loader2, Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useCashDrawerStore } from '@/stores/cashDrawerStore';
import { usePrinterStore } from '@/stores/printerStore';
import { openCashDrawer, getDrawerSettingsFromStore, isTauriEnvironment } from '@/lib/printing';

export function CashDrawerSettings() {
  const { t } = useTranslation('pos');
  const printerConfig = usePrinterStore((s) => s.printerConfig);
  const isTauri = isTauriEnvironment();

  const pin = useCashDrawerStore((s) => s.pin);
  const pulseOnTime = useCashDrawerStore((s) => s.pulseOnTime);
  const pulseOffTime = useCashDrawerStore((s) => s.pulseOffTime);
  const openOnCashSale = useCashDrawerStore((s) => s.openOnCashSale);
  const beepOnOpen = useCashDrawerStore((s) => s.beepOnOpen);
  const setPin = useCashDrawerStore((s) => s.setPin);
  const setPulseOnTime = useCashDrawerStore((s) => s.setPulseOnTime);
  const setPulseOffTime = useCashDrawerStore((s) => s.setPulseOffTime);
  const setOpenOnCashSale = useCashDrawerStore((s) => s.setOpenOnCashSale);
  const setBeepOnOpen = useCashDrawerStore((s) => s.setBeepOnOpen);

  const [isTesting, setIsTesting] = useState(false);

  const handleTestDrawer = useCallback(async () => {
    if (!printerConfig) return;
    setIsTesting(true);
    try {
      await openCashDrawer(printerConfig, getDrawerSettingsFromStore());
    } catch {
      // Silently ignore — settings page doesn't need error UI for test
    } finally {
      setIsTesting(false);
    }
  }, [printerConfig]);

  if (!isTauri || !printerConfig) {
    return (
      <section className="rounded-card bg-white p-4 shadow-sm">
        <h2 className="mb-4 text-base font-bold text-gray-900">
          {t('settings.cashDrawer')}
        </h2>
        <div className="rounded-tile bg-gray-50 p-3 text-center text-sm text-gray-500">
          {!isTauri
            ? t('settings.printerDesktopOnly')
            : t('settings.drawerRequiresPrinter')}
        </div>
      </section>
    );
  }

  return (
    <section className="rounded-card bg-white p-4 shadow-sm">
      <h2 className="mb-4 text-base font-bold text-gray-900">
        {t('settings.cashDrawer')}
      </h2>

      <div className="space-y-4">
        {/* Info banner */}
        <div className="flex items-start gap-2 rounded-tile bg-blue-50 p-3 text-sm text-blue-700">
          <Info className="mt-0.5 h-4 w-4 flex-shrink-0" />
          <span>{t('settings.drawerConnectionInfo')}</span>
        </div>

        {/* Pin selector */}
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-700">
            {t('settings.drawerPin')}
          </label>
          <div className="flex rounded-ctl bg-gray-100 p-1">
            <button
              onClick={() => setPin(0)}
              className={cn(
                'flex flex-1 items-center justify-center rounded-sm px-3 py-2 text-sm font-medium transition-colors',
                pin === 0
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-500 hover:text-gray-700',
              )}
            >
              {t('settings.pin2Standard')}
            </button>
            <button
              onClick={() => setPin(1)}
              className={cn(
                'flex flex-1 items-center justify-center rounded-sm px-3 py-2 text-sm font-medium transition-colors',
                pin === 1
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-500 hover:text-gray-700',
              )}
            >
              {t('settings.pin5')}
            </button>
          </div>
        </div>

        {/* Pulse timing */}
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-700">
              {t('settings.pulseOnTime')}
            </label>
            <div className="flex items-center gap-2">
              <input
                type="number"
                min={1}
                max={255}
                value={pulseOnTime}
                onChange={(e) => setPulseOnTime(Math.min(255, Math.max(1, Number(e.target.value))))}
                className="min-h-[44px] w-full rounded-ctl border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <span className="whitespace-nowrap text-xs text-gray-400">
                ({pulseOnTime * 2}{t('settings.ms')})
              </span>
            </div>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-700">
              {t('settings.pulseOffTime')}
            </label>
            <div className="flex items-center gap-2">
              <input
                type="number"
                min={1}
                max={255}
                value={pulseOffTime}
                onChange={(e) => setPulseOffTime(Math.min(255, Math.max(1, Number(e.target.value))))}
                className="min-h-[44px] w-full rounded-ctl border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <span className="whitespace-nowrap text-xs text-gray-400">
                ({pulseOffTime * 2}{t('settings.ms')})
              </span>
            </div>
          </div>
        </div>

        {/* Open on cash sale toggle */}
        <div className="flex items-center justify-between rounded-tile bg-gray-50 px-3 py-3">
          <div>
            <span className="text-sm font-medium text-gray-900">
              {t('settings.openOnCashSale')}
            </span>
            <p className="text-xs text-gray-500">
              {t('settings.openOnCashSaleDesc')}
            </p>
          </div>
          <button
            onClick={() => setOpenOnCashSale(!openOnCashSale)}
            className={cn(
              'relative inline-flex h-6 w-11 items-center rounded-pill transition-colors',
              openOnCashSale ? 'bg-blue-600' : 'bg-gray-300',
            )}
            role="switch"
            aria-checked={openOnCashSale}
          >
            <span
              className={cn(
                'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                openOnCashSale ? 'translate-x-6' : 'translate-x-1',
              )}
            />
          </button>
        </div>

        {/* Beep on open toggle */}
        <div className="flex items-center justify-between rounded-tile bg-gray-50 px-3 py-3">
          <div>
            <span className="text-sm font-medium text-gray-900">
              {t('settings.beepOnOpen')}
            </span>
            <p className="text-xs text-gray-500">
              {t('settings.beepOnOpenDesc')}
            </p>
          </div>
          <button
            onClick={() => setBeepOnOpen(!beepOnOpen)}
            className={cn(
              'relative inline-flex h-6 w-11 items-center rounded-pill transition-colors',
              beepOnOpen ? 'bg-blue-600' : 'bg-gray-300',
            )}
            role="switch"
            aria-checked={beepOnOpen}
          >
            <span
              className={cn(
                'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                beepOnOpen ? 'translate-x-6' : 'translate-x-1',
              )}
            />
          </button>
        </div>

        {/* Test drawer button */}
        <button
          onClick={() => void handleTestDrawer()}
          disabled={isTesting}
          className="flex w-full items-center justify-center gap-2 rounded-ctl border border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          {isTesting && <Loader2 className="h-4 w-4 animate-spin" />}
          {t('settings.testDrawer')}
        </button>
      </div>
    </section>
  );
}
