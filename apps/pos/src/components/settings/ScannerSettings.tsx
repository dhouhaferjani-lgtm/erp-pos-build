import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Barcode } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useScannerStore } from '@/stores/scannerStore';
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner';

export function ScannerSettings() {
  const { t } = useTranslation('pos');

  const keystrokeThresholdMs = useScannerStore((s) => s.keystrokeThresholdMs);
  const minBarcodeLength = useScannerStore((s) => s.minBarcodeLength);
  const autoAddToCart = useScannerStore((s) => s.autoAddToCart);
  const soundOnScan = useScannerStore((s) => s.soundOnScan);
  const setKeystrokeThresholdMs = useScannerStore((s) => s.setKeystrokeThresholdMs);
  const setMinBarcodeLength = useScannerStore((s) => s.setMinBarcodeLength);
  const setAutoAddToCart = useScannerStore((s) => s.setAutoAddToCart);
  const setSoundOnScan = useScannerStore((s) => s.setSoundOnScan);

  const [lastScanned, setLastScanned] = useState<string | null>(null);

  useBarcodeScanner({
    onScan: (barcode) => {
      setLastScanned(barcode);
    },
    enabled: true,
  });

  return (
    <section className="rounded-card bg-white p-4 shadow-sm">
      <div className="mb-4 flex items-center gap-2">
        <Barcode className="h-5 w-5 text-gray-700" />
        <h2 className="text-base font-bold text-gray-900">
          {t('settings.scanner')}
        </h2>
        <span className="ml-auto rounded-pill bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
          {t('settings.scannerMode')}
        </span>
      </div>

      <div className="space-y-4">
        {/* Sensitivity */}
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">
            {t('settings.scannerSensitivity')}
          </label>
          <div className="flex items-center gap-3">
            <input
              type="range"
              min={20}
              max={200}
              step={5}
              value={keystrokeThresholdMs}
              onChange={(e) => setKeystrokeThresholdMs(Number(e.target.value))}
              className="flex-1"
            />
            <span className="w-16 text-right text-sm text-gray-600">
              {keystrokeThresholdMs}{t('settings.ms')}
            </span>
          </div>
          <p className="mt-1 text-xs text-gray-400">
            {t('settings.scannerSensitivityDesc')}
          </p>
        </div>

        {/* Min barcode length */}
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">
            {t('settings.minBarcodeLength')}
          </label>
          <input
            type="number"
            min={1}
            max={20}
            value={minBarcodeLength}
            onChange={(e) => setMinBarcodeLength(Math.min(20, Math.max(1, Number(e.target.value))))}
            className="min-h-[44px] w-24 rounded-ctl border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Auto-add to cart */}
        <div className="flex items-center justify-between rounded-tile bg-gray-50 px-3 py-3">
          <div>
            <span className="text-sm font-medium text-gray-900">
              {t('settings.autoAddToCart')}
            </span>
            <p className="text-xs text-gray-500">
              {t('settings.autoAddToCartDesc')}
            </p>
          </div>
          <button
            onClick={() => setAutoAddToCart(!autoAddToCart)}
            className={cn(
              'relative inline-flex h-6 w-11 items-center rounded-pill transition-colors',
              autoAddToCart ? 'bg-blue-600' : 'bg-gray-300',
            )}
            role="switch"
            aria-checked={autoAddToCart}
          >
            <span
              className={cn(
                'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                autoAddToCart ? 'translate-x-6' : 'translate-x-1',
              )}
            />
          </button>
        </div>

        {/* Sound on scan */}
        <div className="flex items-center justify-between rounded-tile bg-gray-50 px-3 py-3">
          <div>
            <span className="text-sm font-medium text-gray-900">
              {t('settings.soundOnScan')}
            </span>
            <p className="text-xs text-gray-500">
              {t('settings.soundOnScanDesc')}
            </p>
          </div>
          <button
            onClick={() => setSoundOnScan(!soundOnScan)}
            className={cn(
              'relative inline-flex h-6 w-11 items-center rounded-pill transition-colors',
              soundOnScan ? 'bg-blue-600' : 'bg-gray-300',
            )}
            role="switch"
            aria-checked={soundOnScan}
          >
            <span
              className={cn(
                'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                soundOnScan ? 'translate-x-6' : 'translate-x-1',
              )}
            />
          </button>
        </div>

        {/* Scan test area */}
        <div className="rounded-tile border border-dashed border-gray-300 p-3 text-center">
          <p className="text-xs font-medium uppercase text-gray-500">
            {t('settings.scanTestArea')}
          </p>
          <p className="mt-1 text-sm font-mono text-gray-900">
            {lastScanned ?? t('settings.scanTestPlaceholder')}
          </p>
        </div>
      </div>
    </section>
  );
}
