import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { cn } from '@/lib/utils';
import { usePrinterStore } from '@/stores/printerStore';
import type { PaperWidth, CutMode, PrinterEncoding } from '@/stores/printerStore';

export function PrinterAdvancedSettings() {
  const { t } = useTranslation('pos');
  const [expanded, setExpanded] = useState(false);

  const printerConfig = usePrinterStore((s) => s.printerConfig);
  const settings = usePrinterStore((s) => s.settings);
  const updateSettings = usePrinterStore((s) => s.updateSettings);

  if (!printerConfig) return null;

  return (
    <div className="border-t border-gray-100 pt-3">
      <button
        onClick={() => setExpanded(!expanded)}
        className="flex w-full items-center justify-between text-sm font-medium text-gray-700"
      >
        {t('settings.advancedPrinter')}
        {expanded ? (
          <ChevronUp className="h-4 w-4" />
        ) : (
          <ChevronDown className="h-4 w-4" />
        )}
      </button>

      {expanded && (
        <div className="mt-3 space-y-4">
          {/* Paper width */}
          <div>
            <label className="mb-2 block text-sm font-medium text-gray-700">
              {t('settings.paperWidth')}
            </label>
            <div className="flex rounded-lg bg-gray-100 p-1">
              {(['80mm', '58mm'] as PaperWidth[]).map((width) => (
                <button
                  key={width}
                  onClick={() => updateSettings({ paperWidth: width })}
                  className={cn(
                    'flex flex-1 items-center justify-center rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    settings.paperWidth === width
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-500 hover:text-gray-700',
                  )}
                >
                  {width === '80mm' ? t('settings.paper80mm') : t('settings.paper58mm')}
                </button>
              ))}
            </div>
          </div>

          {/* Cut mode */}
          <div>
            <label className="mb-2 block text-sm font-medium text-gray-700">
              {t('settings.cutMode')}
            </label>
            <div className="flex rounded-lg bg-gray-100 p-1">
              {(['partial', 'full', 'none'] as CutMode[]).map((mode) => (
                <button
                  key={mode}
                  onClick={() => updateSettings({ cutMode: mode })}
                  className={cn(
                    'flex flex-1 items-center justify-center rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    settings.cutMode === mode
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-500 hover:text-gray-700',
                  )}
                >
                  {t(`settings.cut${mode.charAt(0).toUpperCase() + mode.slice(1)}`)}
                </button>
              ))}
            </div>
          </div>

          {/* Copies */}
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              {t('settings.copies')}
            </label>
            <input
              type="number"
              min={1}
              max={5}
              value={settings.copies}
              onChange={(e) =>
                updateSettings({ copies: Math.min(5, Math.max(1, Number(e.target.value))) })
              }
              className="min-h-[44px] w-24 rounded-lg border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {/* Footer text */}
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              {t('settings.footerText')}
            </label>
            <input
              type="text"
              value={settings.footerText}
              onChange={(e) => updateSettings({ footerText: e.target.value })}
              placeholder={t('settings.footerTextPlaceholder')}
              className="min-h-[44px] w-full rounded-lg border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {/* Encoding */}
          <div>
            <label htmlFor="encoding-select" className="mb-1 block text-sm font-medium text-gray-700">
              {t('settings.encoding')}
            </label>
            <select
              id="encoding-select"
              value={settings.encoding}
              onChange={(e) =>
                updateSettings({ encoding: e.target.value as PrinterEncoding })
              }
              className="min-h-[44px] w-full rounded-lg border border-gray-300 bg-white px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="cp437">{t('printer.charset.cp437')}</option>
              <option value="cp858">{t('printer.charset.cp858')}</option>
              <option value="cp1252">{t('printer.charset.cp1252')}</option>
            </select>
          </div>
        </div>
      )}
    </div>
  );
}
