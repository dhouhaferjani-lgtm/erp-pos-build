import { useEffect, useState, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { usePrinterStore } from '@/stores/printerStore';
import { useCashDrawerStore } from '@/stores/cashDrawerStore';
import {
  printReceipt,
  openCashDrawer,
  getPrintSettingsFromStore,
  getDrawerSettingsFromStore,
  isTauriEnvironment,
} from '@/lib/printing';
import type { ReceiptData } from '@/lib/printing';
import { Modal } from './Modal';
import { CheckCircle, Printer, Loader2 } from 'lucide-react';

interface CheckoutSuccessModalProps {
  isOpen: boolean;
  onClose: () => void;
  receiptNumber: string;
  total: string;
  changeDue: number;
  /** Optional pre-built receipt data for ESC/POS printing. */
  receiptData?: ReceiptData;
}

export function CheckoutSuccessModal({
  isOpen,
  onClose,
  receiptNumber,
  total,
  changeDue,
  receiptData,
}: CheckoutSuccessModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  const printerConfig = usePrinterStore((s) => s.printerConfig);
  const autoPrint = usePrinterStore((s) => s.autoPrint);

  const [isPrinting, setIsPrinting] = useState(false);
  const [printError, setPrintError] = useState<string | null>(null);
  const autoPrintTriggered = useRef(false);

  // ESC/POS thermal printer
  const handlePrint = useCallback(async () => {
    if (!printerConfig || !receiptData) return;
    setIsPrinting(true);
    setPrintError(null);
    try {
      const printSettings = getPrintSettingsFromStore();
      await printReceipt(receiptData, printerConfig, printSettings);
    } catch (err: unknown) {
      setPrintError(err instanceof Error ? err.message : String(err));
    } finally {
      setIsPrinting(false);
    }
  }, [printerConfig, receiptData]);

  // Auto-print when the modal opens with receipt data + auto-open drawer
  useEffect(() => {
    if (
      isOpen &&
      autoPrint &&
      printerConfig &&
      receiptData &&
      isTauriEnvironment() &&
      !autoPrintTriggered.current
    ) {
      autoPrintTriggered.current = true;
      void handlePrint();

      // Auto-open cash drawer on cash sale
      const { openOnCashSale } = useCashDrawerStore.getState();
      if (openOnCashSale) {
        void openCashDrawer(printerConfig, getDrawerSettingsFromStore());
      }
    }
    if (!isOpen) {
      autoPrintTriggered.current = false;
    }
  }, [isOpen, autoPrint, printerConfig, receiptData, handlePrint]);

  const canEscPosPrint =
    isTauriEnvironment() && printerConfig !== null && receiptData !== undefined;

  const footerContent = (
    <div className="space-y-3">
      {/* ESC/POS thermal printer — primary action when physical printer is configured */}
      {canEscPosPrint && (
        <button
          onClick={() => void handlePrint()}
          disabled={isPrinting}
          className="flex w-full items-center justify-center gap-2 rounded-ctl border border-border-strong bg-surface-raised px-6 py-3 text-base font-medium text-ink-muted transition-colors hover:bg-surface-sunken disabled:opacity-50"
        >
          {isPrinting ? (
            <Loader2 className="h-5 w-5 animate-spin" />
          ) : (
            <Printer className="h-5 w-5" />
          )}
          {t('settings.printReceipt')}
        </button>
      )}

      {/* New sale button */}
      <button
        onClick={onClose}
        className="w-full rounded-ctl bg-action px-6 py-4 text-lg font-semibold text-ink-inverse transition-colors hover:bg-action-hover"
      >
        {t('payment.newTransaction')}
      </button>
    </div>
  );

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('payment.success')} size="md" footer={footerContent}>
      <div className="space-y-6 text-center">
        {/* Success icon */}
        <div className="flex justify-center">
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-success-surface">
            <CheckCircle className="h-12 w-12 text-success-strong" />
          </div>
        </div>

        {/* Receipt number */}
        <div>
          <p className="text-sm text-ink-muted">{t('payment.transactionComplete')}</p>
          <p className="mt-1 text-2xl font-bold text-ink">
            {t('payment.receiptNumber', { number: receiptNumber })}
          </p>
        </div>

        {/* Total */}
        <div className="rounded-card bg-surface-sunken p-4">
          <p className="text-sm text-ink-muted">{t('common:total')}</p>
          <p className="text-xl font-bold text-ink">{format(total)}</p>
        </div>

        {/* Change due */}
        {changeDue > 0 && (
          <div className="rounded-card border border-success-subtle bg-success-surface p-4">
            <p className="text-sm font-medium text-success-strong">
              {t('cashTendered.changeDue')}
            </p>
            <p className="text-2xl font-bold text-success-strong">{format(changeDue)}</p>
          </div>
        )}

        {/* Print error */}
        {printError && (
          <div className="rounded-sm bg-danger-surface p-3 text-sm text-danger-strong">
            {printError}
          </div>
        )}
      </div>
    </Modal>
  );
}
