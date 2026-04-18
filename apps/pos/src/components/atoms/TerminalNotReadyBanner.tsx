import { useTranslation } from 'react-i18next';
import { useTerminalStore } from '@/stores/terminalStore';
import { useSyncStore } from '@/stores/syncStore';

export function TerminalNotReadyBanner() {
  const { t } = useTranslation('pos');
  const hashChainReady = useTerminalStore((s) => s.hashChainReady);
  const scheduler = useSyncStore((s) => s.scheduler);

  if (hashChainReady) return null;

  const handleSyncNow = () => {
    if (!scheduler) return;
    void scheduler.syncNow().catch(() => { /* handled inside scheduler */ });
  };

  return (
    <div
      role="alert"
      className="bg-amber-50 border border-amber-300 text-amber-900 rounded-lg p-4 m-4 flex items-start gap-3"
    >
      <div className="flex-1">
        <h3 className="font-semibold text-base">{t('terminalNotReady.title')}</h3>
        <p className="text-sm mt-1">{t('terminalNotReady.description')}</p>
      </div>
      <button
        type="button"
        className="px-3 py-2 border border-amber-400 rounded-md text-sm font-medium hover:bg-amber-100"
        onClick={handleSyncNow}
      >
        {t('terminalNotReady.syncNow')}
      </button>
    </div>
  );
}
