import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Monitor, Play, Square, RefreshCw, Loader2, AlertCircle, CheckCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { isTauriEnvironment } from '@/lib/printing';
import { useCustomerDisplayStore } from '@/stores/customerDisplayStore';
import type { MonitorInfo } from '@/stores/customerDisplayStore';
import {
  listMonitors,
  openCustomerDisplay,
  closeCustomerDisplay,
  sendIdleScreen,
} from '@/lib/customerDisplay';

export function CustomerDisplaySettings() {
  const { t } = useTranslation('pos');
  const isTauri = isTauriEnvironment();

  const enabled = useCustomerDisplayStore((s) => s.enabled);
  const monitorIndex = useCustomerDisplayStore((s) => s.monitorIndex);
  const idleImagePath = useCustomerDisplayStore((s) => s.idleImagePath);
  const isOpen = useCustomerDisplayStore((s) => s.isOpen);
  const availableMonitors = useCustomerDisplayStore((s) => s.availableMonitors);
  const setEnabled = useCustomerDisplayStore((s) => s.setEnabled);
  const setMonitorIndex = useCustomerDisplayStore((s) => s.setMonitorIndex);
  const setIdleImagePath = useCustomerDisplayStore((s) => s.setIdleImagePath);
  const setIsOpen = useCustomerDisplayStore((s) => s.setIsOpen);
  const setAvailableMonitors = useCustomerDisplayStore((s) => s.setAvailableMonitors);

  const [isScanning, setIsScanning] = useState(false);
  const [isOpening, setIsOpening] = useState(false);
  const [status, setStatus] = useState<'idle' | 'success' | 'error'>('idle');
  const [statusMessage, setStatusMessage] = useState('');

  // Don't auto-scan monitors on mount — it can crash under certain
  // display server configurations (e.g. VS Code snap + X11).
  // Users click "Rescan" manually when they enable the feature.

  const handleScanMonitors = useCallback(async () => {
    setIsScanning(true);
    setStatus('idle');
    setStatusMessage('');
    try {
      const monitors = await listMonitors();
      setAvailableMonitors(monitors);
      if (monitors.length <= 1) {
        setStatus('error');
        setStatusMessage(t('settings.cfd.singleMonitor'));
      }
    } catch (err: unknown) {
      setStatus('error');
      setStatusMessage(err instanceof Error ? err.message : String(err));
    } finally {
      setIsScanning(false);
    }
  }, [t, setAvailableMonitors]);

  const handleToggleEnabled = useCallback(
    (next: boolean) => {
      setEnabled(next);
      if (!next && isOpen) {
        void closeCustomerDisplay().then(() => setIsOpen(false));
      }
    },
    [setEnabled, isOpen, setIsOpen],
  );

  const handleOpenPreview = useCallback(async () => {
    setIsOpening(true);
    setStatus('idle');
    setStatusMessage('');
    try {
      await openCustomerDisplay(monitorIndex ?? undefined);
      setIsOpen(true);
      // Send idle screen initially
      await sendIdleScreen(idleImagePath);
      setStatus('success');
      setStatusMessage(t('settings.cfd.displayOpened'));
    } catch (err: unknown) {
      setStatus('error');
      setStatusMessage(err instanceof Error ? err.message : String(err));
    } finally {
      setIsOpening(false);
    }
  }, [monitorIndex, idleImagePath, t, setIsOpen]);

  const handleClose = useCallback(async () => {
    try {
      await closeCustomerDisplay();
      setIsOpen(false);
    } catch {
      // Non-critical
    }
  }, [setIsOpen]);

  const handleMonitorSelect = useCallback(
    (index: number) => {
      setMonitorIndex(index);
    },
    [setMonitorIndex],
  );

  if (!isTauri) {
    return (
      <section className="rounded-card bg-white p-4 shadow-sm">
        <div className="mb-4 flex items-center gap-2">
          <Monitor className="h-5 w-5 text-gray-700" />
          <h2 className="text-base font-bold text-gray-900">
            {t('settings.cfd.title')}
          </h2>
        </div>
        <div className="rounded-tile bg-amber-50 p-3 text-sm text-amber-700">
          {t('settings.cfd.desktopOnly')}
        </div>
      </section>
    );
  }

  const secondaryMonitors = availableMonitors.filter((m) => !m.is_primary);

  return (
    <section className="rounded-card bg-white p-4 shadow-sm">
      <div className="mb-4 flex items-center gap-2">
        <Monitor className="h-5 w-5 text-gray-700" />
        <h2 className="text-base font-bold text-gray-900">
          {t('settings.cfd.title')}
        </h2>
      </div>

      <div className="space-y-4">
        {/* Enable toggle */}
        <div className="flex items-center justify-between rounded-tile bg-gray-50 px-3 py-3">
          <div>
            <span className="text-sm font-medium text-gray-900">
              {t('settings.cfd.enable')}
            </span>
            <p className="text-xs text-gray-500">
              {t('settings.cfd.enableDesc')}
            </p>
          </div>
          <button
            onClick={() => handleToggleEnabled(!enabled)}
            className={cn(
              'relative inline-flex h-6 w-11 items-center rounded-pill transition-colors',
              enabled ? 'bg-blue-600' : 'bg-gray-300',
            )}
            role="switch"
            aria-checked={enabled}
          >
            <span
              className={cn(
                'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                enabled ? 'translate-x-6' : 'translate-x-1',
              )}
            />
          </button>
        </div>

        {enabled && (
          <>
            {/* Monitor selector */}
            <div>
              <div className="mb-2 flex items-center justify-between">
                <label className="text-sm font-medium text-gray-700">
                  {t('settings.cfd.selectMonitor')}
                </label>
                <button
                  onClick={() => void handleScanMonitors()}
                  disabled={isScanning}
                  className="flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 disabled:opacity-50"
                >
                  {isScanning ? (
                    <Loader2 className="h-3 w-3 animate-spin" />
                  ) : (
                    <RefreshCw className="h-3 w-3" />
                  )}
                  {t('settings.cfd.rescan')}
                </button>
              </div>

              {availableMonitors.length === 0 ? (
                <div className="rounded-tile bg-gray-50 p-3 text-center text-sm text-gray-500">
                  {t('settings.cfd.noMonitors')}
                </div>
              ) : (
                <div className="space-y-2">
                  {/* Auto-detect option */}
                  <MonitorOption
                    label={t('settings.cfd.autoDetect')}
                    description={t('settings.cfd.autoDetectDesc')}
                    isSelected={monitorIndex === null}
                    onClick={() => setMonitorIndex(null)}
                  />
                  {availableMonitors.map((monitor, index) => (
                    <MonitorOption
                      key={index}
                      label={monitorLabel(monitor, index, t)}
                      description={`${monitor.size[0]}x${monitor.size[1]}`}
                      isSelected={monitorIndex === index}
                      onClick={() => handleMonitorSelect(index)}
                      isPrimary={monitor.is_primary}
                    />
                  ))}
                </div>
              )}

              {secondaryMonitors.length === 0 && availableMonitors.length > 0 && (
                <div className="mt-2 rounded-tile bg-amber-50 p-3 text-xs text-amber-700">
                  {t('settings.cfd.singleMonitor')}
                </div>
              )}
            </div>

            {/* Idle image path */}
            <div>
              <label
                htmlFor="idle-image-path"
                className="mb-2 block text-sm font-medium text-gray-700"
              >
                {t('settings.cfd.idleImage')}
              </label>
              <input
                id="idle-image-path"
                type="text"
                value={idleImagePath}
                onChange={(e) => setIdleImagePath(e.target.value)}
                placeholder={t('settings.cfd.idleImagePlaceholder')}
                className="min-h-[44px] w-full rounded-ctl border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-400">
                {t('settings.cfd.idleImageHint')}
              </p>
            </div>

            {/* Preview / Close buttons */}
            <div className="flex gap-2">
              {!isOpen ? (
                <button
                  onClick={() => void handleOpenPreview()}
                  disabled={isOpening}
                  className="flex flex-1 items-center justify-center gap-2 rounded-ctl bg-blue-600 px-4 py-3 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  {isOpening ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Play className="h-4 w-4" />
                  )}
                  {t('settings.cfd.preview')}
                </button>
              ) : (
                <button
                  onClick={() => void handleClose()}
                  className="flex flex-1 items-center justify-center gap-2 rounded-ctl border border-red-300 bg-white px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50"
                >
                  <Square className="h-4 w-4" />
                  {t('settings.cfd.close')}
                </button>
              )}
            </div>
          </>
        )}

        {/* Status message */}
        {status !== 'idle' && statusMessage && (
          <div
            className={cn(
              'flex items-center gap-2 rounded-tile p-3 text-sm',
              status === 'success'
                ? 'bg-green-50 text-green-700'
                : 'bg-red-50 text-red-700',
            )}
          >
            {status === 'success' ? (
              <CheckCircle className="h-4 w-4 flex-shrink-0" />
            ) : (
              <AlertCircle className="h-4 w-4 flex-shrink-0" />
            )}
            {statusMessage}
          </div>
        )}
      </div>
    </section>
  );
}

function MonitorOption({
  label,
  description,
  isSelected,
  onClick,
  isPrimary,
}: {
  label: string;
  description: string;
  isSelected: boolean;
  onClick: () => void;
  isPrimary?: boolean;
}) {
  const { t } = useTranslation('pos');
  return (
    <button
      onClick={onClick}
      className={cn(
        'flex w-full items-center justify-between rounded-tile border px-3 py-3 text-left transition-colors',
        isSelected
          ? 'border-blue-300 bg-blue-50'
          : 'border-gray-200 bg-white hover:bg-gray-50',
      )}
    >
      <div>
        <p className="text-sm font-medium text-gray-900">
          {label}
          {isPrimary && (
            <span className="ml-2 rounded-sm bg-gray-200 px-1.5 py-0.5 text-xs text-gray-600">
              {t('settings.cfd.primaryBadge')}
            </span>
          )}
        </p>
        <p className="text-xs text-gray-500">{description}</p>
      </div>
      {isSelected && <CheckCircle className="h-5 w-5 text-blue-600" />}
    </button>
  );
}

function monitorLabel(
  monitor: MonitorInfo,
  index: number,
  t: (key: string, options?: Record<string, unknown>) => string,
): string {
  if (monitor.name) {
    return monitor.name;
  }
  return t('settings.cfd.monitorFallback', { number: index + 1 });
}
