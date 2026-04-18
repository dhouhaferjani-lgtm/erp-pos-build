import { useTranslation } from 'react-i18next';
import { useSyncStore } from '@/stores/syncStore';

export function ChainBreakAlert() {
  const { t } = useTranslation('pos');
  const chainBreak = useSyncStore((s) => s.chainBreak);
  const chainBreakReceiptNumber = useSyncStore((s) => s.chainBreakReceiptNumber);
  const acknowledgeChainBreak = useSyncStore((s) => s.acknowledgeChainBreak);

  if (!chainBreak) return null;

  return (
    <div
      role="alert"
      className="bg-red-50 border border-red-300 text-red-900 rounded-lg p-4 m-4 flex items-start gap-3"
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
        className="px-3 py-2 border border-red-400 rounded-md text-sm font-medium hover:bg-red-100"
        onClick={acknowledgeChainBreak}
      >
        {t('chainBreak.acknowledge')}
      </button>
    </div>
  );
}
