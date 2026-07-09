import { useTranslation } from 'react-i18next';
import { useSyncStore } from '@/stores/syncStore';

export function ChainBreakAlert() {
  const { t } = useTranslation('pos');
  const chainBreak = useSyncStore((s) => s.chainBreak);
  const chainBreakReceiptNumber = useSyncStore((s) => s.chainBreakReceiptNumber);
  const chainBreakAcknowledgedAt = useSyncStore((s) => s.chainBreakAcknowledgedAt);
  const acknowledgeChainBreak = useSyncStore((s) => s.acknowledgeChainBreak);

  if (!chainBreak) return null;

  if (chainBreakAcknowledgedAt !== null) {
    const time = new Intl.DateTimeFormat(undefined, {
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(chainBreakAcknowledgedAt));

    return (
      <div
        role="alert"
        className="bg-red-50 border border-red-300 text-red-900 rounded-tile px-4 py-2 m-4 flex items-center gap-3"
      >
        <div className="flex-1">
          <span className="font-semibold text-sm">{t('chainBreak.title')}</span>
          {chainBreakReceiptNumber && (
            <span className="text-sm ml-2">
              — {chainBreakReceiptNumber}
            </span>
          )}
          <span className="text-sm ml-2 text-red-700">
            {t('chainBreak.acknowledgedAt', { time })}
          </span>
        </div>
      </div>
    );
  }

  return (
    <div
      role="alert"
      className="bg-red-50 border border-red-300 text-red-900 rounded-tile p-4 m-4 flex items-start gap-3"
    >
      <div className="flex-1">
        <h3 className="font-semibold text-base">{t('chainBreak.title')}</h3>
        <p className="text-sm mt-1">
          {t('chainBreak.description', {
            receiptNumber: chainBreakReceiptNumber ?? t('chainBreak.unknownReceipt'),
          })}
        </p>
        <p className="text-sm mt-2">{t('chainBreak.contactSupport')}</p>
      </div>
      <button
        type="button"
        className="px-3 py-2 border border-red-400 rounded-ctl text-sm font-medium hover:bg-red-100"
        onClick={acknowledgeChainBreak}
      >
        {t('chainBreak.acknowledge')}
      </button>
    </div>
  );
}
