import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from './Modal';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

interface ReceiptScanConfirmationSheetProps {
  /**
   * The local SQLite mirror entry for the scanned receipt. When `null`,
   * the sheet renders nothing (treated as closed). The component never
   * fetches over the network — all displayed data comes from this prop.
   */
  entry: LocalReceiptQrIndexEntry | null;
  /** Cashier dismissed the sheet. Cart MUST remain unchanged. */
  onCancel: () => void;
  /** Cashier accepted; Task 52 will hydrate the cart from the accepted event. */
  onAccept: () => void;
}

/**
 * Receipt-Scan Confirmation Sheet (Phase H Task 50, spec §6.1 entry 2).
 *
 * Cashier scans an old sale receipt mid-sale; this sheet asks them whether
 * to start a refund/exchange. NEVER mutates the cart — that's Task 52's
 * job, gated on the cashier explicitly tapping "Start refund". All copy
 * and the receipt date are read from `entry`, which the dispatcher loaded
 * from the local `receipt_qr_index` mirror.
 */
export function ReceiptScanConfirmationSheet({
  entry,
  onCancel,
  onAccept,
}: ReceiptScanConfirmationSheetProps) {
  const { t, i18n } = useTranslation('pos');

  const formattedDate = useMemo(() => {
    if (entry === null) return '';
    const ts = Date.parse(entry.posted_at);
    if (Number.isNaN(ts)) return entry.posted_at;
    try {
      return new Intl.DateTimeFormat(i18n.language, {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(new Date(ts));
    } catch {
      return entry.posted_at;
    }
  }, [entry, i18n.language]);

  if (entry === null) return null;
  // Escape dismissal is handled by Modal (it binds Escape → onClose, which
  // is wired to onCancel below). No second listener here — duplicating it
  // would fire onCancel twice per press.

  return (
    <Modal
      isOpen={true}
      onClose={onCancel}
      title={t('receiptScan.title')}
      size="md"
      footer={
        <div className="flex justify-end gap-3">
          <button
            type="button"
            onClick={onCancel}
            className="rounded-ctl border border-border-strong bg-surface-raised px-4 py-2 text-sm font-medium text-ink-muted hover:bg-surface-sunken"
          >
            {t('receiptScan.cancel')}
          </button>
          <button
            type="button"
            onClick={onAccept}
            className="rounded-ctl bg-action px-4 py-2 text-sm font-medium text-ink-inverse hover:bg-action-hover"
          >
            {t('receiptScan.startRefund')}
          </button>
        </div>
      }
    >
      <p className="text-base text-ink">
        {t('receiptScan.body', { date: formattedDate })}
      </p>
    </Modal>
  );
}
