import React from 'react'
import { useTranslation } from 'react-i18next'
import { Printer, Download, Loader2 } from 'lucide-react'
import { POSButton } from '../atoms/POSButton'
import { useReceiptPrint } from '../hooks/useReceiptPrint'
import { cn } from '@/lib/utils'

export interface ReceiptPrintButtonProps {
  receiptId: string
  showDownload?: boolean
  autoPrint?: boolean
  className?: string
  onPrintComplete?: () => void
}

/**
 * Receipt Print Button Component
 *
 * Displays print and optional download buttons for receipts.
 * Handles auto-print based on company settings.
 */
export function ReceiptPrintButton({
  receiptId,
  showDownload = true,
  autoPrint = false,
  className,
  onPrintComplete,
}: ReceiptPrintButtonProps) {
  const { t } = useTranslation(['pos'])
  const { printReceipt, downloadReceipt, isPrinting, isDownloading } =
    useReceiptPrint()

  // Auto-print on mount if enabled
  React.useEffect(() => {
    if (autoPrint && receiptId) {
      handlePrint()
    }
  }, [autoPrint, receiptId])

  const handlePrint = async () => {
    await printReceipt(receiptId)
    onPrintComplete?.()
  }

  const handleDownload = async () => {
    await downloadReceipt(receiptId)
  }

  return (
    <div className={cn('flex gap-2', className)}>
      <POSButton
        onClick={handlePrint}
        disabled={isPrinting || isDownloading}
        variant="primary"
        size="lg"
        icon={isPrinting ? <Loader2 className="animate-spin" /> : <Printer />}
        className="flex-1"
      >
        {isPrinting ? t('pos:receipt.printing') : t('pos:receipt.print')}
      </POSButton>

      {showDownload && (
        <POSButton
          onClick={handleDownload}
          disabled={isPrinting || isDownloading}
          variant="secondary"
          size="lg"
          icon={isDownloading ? <Loader2 className="animate-spin" /> : <Download />}
        >
          <span className="sr-only">{t('pos:receipt.download')}</span>
        </POSButton>
      )}
    </div>
  )
}
