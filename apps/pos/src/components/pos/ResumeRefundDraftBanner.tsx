import { useTranslation } from 'react-i18next';
import { RotateCcw } from 'lucide-react';

interface ResumeRefundDraftBannerProps {
  receiptNumber: string;
  onResume: () => void;
  onDiscard: () => void;
}

/**
 * Fixed-height banner shown on the HomePage when a refund draft is detected
 * for this terminal after the shift opens. Cashier can resume or discard.
 *
 * Per project memory: modals/banners must have fixed dimensions.
 */
export function ResumeRefundDraftBanner({
  receiptNumber,
  onResume,
  onDiscard,
}: ResumeRefundDraftBannerProps) {
  const { t } = useTranslation('pos');

  return (
    <div
      role="alert"
      className="flex h-14 w-full items-center justify-between gap-4 border-b border-warning-subtle bg-warning-surface px-4"
    >
      <div className="flex items-center gap-2 text-warning-strong">
        <RotateCcw className="h-5 w-5 shrink-0 text-warning-strong" aria-hidden="true" />
        <span className="text-sm font-medium">
          {t('refundFlow.resumeBanner.title', { number: receiptNumber })}
        </span>
      </div>

      <div className="flex shrink-0 items-center gap-2">
        <button
          onClick={onResume}
          className="rounded-md bg-warning px-3 py-1.5 text-sm font-semibold text-ink-inverse hover:bg-warning-strong active:bg-warning-strong"
        >
          {t('refundFlow.resumeBanner.resume')}
        </button>
        <button
          onClick={onDiscard}
          className="rounded-md border border-warning-subtle bg-surface-raised px-3 py-1.5 text-sm font-medium text-warning-strong hover:bg-warning-surface active:bg-warning-surface"
        >
          {t('refundFlow.resumeBanner.discard')}
        </button>
      </div>
    </div>
  );
}
