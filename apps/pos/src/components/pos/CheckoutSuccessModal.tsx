import { useEffect, useState, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { usePrinterStore } from '@/stores/printerStore';
import { useCashDrawerStore } from '@/stores/cashDrawerStore';
import {
  printReceipt,
  printReceiptAsPdf,
  openCashDrawer,
  getPrintSettingsFromStore,
  getDrawerSettingsFromStore,
  isTauriEnvironment,
} from '@/lib/printing';
import type { ReceiptData } from '@/lib/printing';
import { Modal } from './Modal';
import { CheckCircle, Printer, FileText, Loader2 } from 'lucide-react';

interface CheckoutSuccessModalProps {
  isOpen: boolean;
  onClose: () => void;
  receiptNumber: string;
  total: string;
  changeDue: number;
  /** Receipt ID for PDF printing. */
  receiptId?: string;
  /** Optional pre-built receipt data for ESC/POS printing. */
  receiptData?: ReceiptData;
}

export function CheckoutSuccessModal({
  isOpen,
  onClose,
  receiptNumber,
  total,
  changeDue,
  receiptId,
  receiptData,
}: CheckoutSuccessModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const isOnline = useConnectivityStore((s) => s.isOnline);

  const printerConfig = usePrinterStore((s) => s.printerConfig);
  const autoPrint = usePrinterStore((s) => s.autoPrint);

  const [isPrinting, setIsPrinting] = useState(false);
  const [isPdfPrinting, setIsPdfPrinting] = useState(false);
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

  // PDF print via browser dialog
  const handlePdfPrint = useCallback(async () => {
    if (!receiptId) return;
    setIsPdfPrinting(true);
    setPrintError(null);
    try {
      await printReceiptAsPdf(receiptId);
    } catch (err: unknown) {
      setPrintError(err instanceof Error ? err.message : String(err));
    } finally {
      setIsPdfPrinting(false);
    }
  }, [receiptId]);

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
  const canPdfPrint = isOnline && receiptId !== undefined;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('payment.success')} size="md">
      <div className="space-y-6 text-center">
        {/* Success icon */}
        <div className="flex justify-center">
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-green-100">
            <CheckCircle className="h-12 w-12 text-green-600" />
          </div>
        </div>

        {/* Offline banner */}
        {!isOnline && (
          <div className="rounded-md bg-amber-50 p-3 text-sm text-amber-700">
            {t('sync.receiptQueued')}
          </div>
        )}

        {/* Receipt number */}
        <div>
          <p className="text-sm text-gray-600">{t('payment.transactionComplete')}</p>
          <p className="mt-1 text-2xl font-bold text-gray-900">
            {t('payment.receiptNumber', { number: receiptNumber })}
          </p>
        </div>

        {/* Total */}
        <div className="rounded-xl bg-gray-50 p-4">
          <p className="text-sm text-gray-700">{t('common:total')}</p>
          <p className="text-xl font-bold text-gray-900">{format(total)}</p>
        </div>

        {/* Change due */}
        {changeDue > 0 && (
          <div className="rounded-xl border border-green-200 bg-green-50 p-4">
            <p className="text-sm font-medium text-green-600">
              {t('cashTendered.changeDue')}
            </p>
            <p className="text-2xl font-bold text-green-700">{format(changeDue)}</p>
          </div>
        )}

        {/* Print error */}
        {printError && (
          <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">
            {printError}
          </div>
        )}

        {/* Action buttons */}
        <div className="space-y-3">
          {/* PDF Print — always available when online */}
          {canPdfPrint && (
            <button
              onClick={() => void handlePdfPrint()}
              disabled={isPdfPrinting}
              className="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-6 py-3 text-base font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-50"
            >
              {isPdfPrinting ? (
                <Loader2 className="h-5 w-5 animate-spin" />
              ) : (
                <FileText className="h-5 w-5" />
              )}
              {t('settings.printReceiptPdf')}
            </button>
          )}

          {/* ESC/POS thermal printer — only when physical printer is configured */}
          {canEscPosPrint && (
            <button
              onClick={() => void handlePrint()}
              disabled={isPrinting}
              className="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-6 py-3 text-base font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-50"
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
            className="w-full rounded-xl bg-blue-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-blue-700"
          >
            {t('payment.newTransaction')}
          </button>
        </div>
      </div>
    </Modal>
  );
}
