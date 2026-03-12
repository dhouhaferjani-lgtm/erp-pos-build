import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Monitor, Image, Globe } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useSettingsStore, SUPPORTED_LANGUAGES } from '@/stores/settingsStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useConnectivityStore } from '@/stores/connectivityStore';

export function SettingsPage() {
  const { t } = useTranslation('pos');
  const navigate = useNavigate();

  const displayMode = useSettingsStore((s) => s.displayMode);
  const language = useSettingsStore((s) => s.language);
  const setDisplayMode = useSettingsStore((s) => s.setDisplayMode);
  const setLanguage = useSettingsStore((s) => s.setLanguage);

  const terminal = useTerminalStore((s) => s.terminal);
  const shift = useTerminalStore((s) => s.shift);

  const serverUrl = useAuthStore((s) => s.serverUrl);

  const isOnline = useConnectivityStore((s) => s.isOnline);

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

          {/* Peripherals */}
          <section className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-gray-900">
              {t('settings.peripherals')}
            </h2>
            <div className="space-y-3">
              {[
                { label: t('settings.printer'), status: t('settings.comingSoon') },
                { label: t('settings.scanner'), status: t('settings.comingSoon') },
                { label: t('settings.cashDrawer'), status: t('settings.comingSoon') },
                { label: t('settings.kitchenPrinter'), status: t('settings.comingSoon') },
              ].map((item) => (
                <div
                  key={item.label}
                  className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3"
                >
                  <span className="text-sm font-medium text-gray-900">{item.label}</span>
                  <span className="text-xs text-gray-400">{item.status}</span>
                </div>
              ))}
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
                  <span className="font-medium text-gray-900">{shift.status}</span>
                </div>
              )}
            </div>
          </section>

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
    </div>
  );
}
