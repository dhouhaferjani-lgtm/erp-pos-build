import { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { printReceipt, downloadReceipt } from '../api/receiptApi'

interface UseReceiptPrintReturn {
  printReceipt: (receiptId: string) => Promise<void>
  downloadReceipt: (receiptId: string) => Promise<void>
  isPrinting: boolean
  isDownloading: boolean
}

/**
 * Hook for printing and downloading POS receipts
 *
 * Handles PDF generation and browser print dialog interaction.
 * Supports auto-print based on company settings.
 */
export function useReceiptPrint(): UseReceiptPrintReturn {
  const { t } = useTranslation(['pos'])
  const [isPrinting, setIsPrinting] = useState(false)
  const [isDownloading, setIsDownloading] = useState(false)

  const handlePrint = useCallback(
    async (receiptId: string) => {
      setIsPrinting(true)

      try {
        // Fetch PDF blob
        const blob = await printReceipt(receiptId)

        // Create object URL
        const url = window.URL.createObjectURL(blob)

        // Open in new window for printing
        const printWindow = window.open(url, '_blank')

        if (printWindow) {
          // Wait for PDF to load, then trigger print dialog
          printWindow.onload = () => {
            printWindow.print()
          }

          // Clean up URL after a delay
          setTimeout(() => {
            window.URL.revokeObjectURL(url)
          }, 1000)

          toast.success(t('pos:receipt.printSuccess'))
        } else {
          // Popup blocked - fallback to download
          toast.warning(t('pos:receipt.popupBlocked'))
          const a = document.createElement('a')
          a.href = url
          a.download = `receipt-${receiptId}.pdf`
          document.body.appendChild(a)
          a.click()
          window.URL.revokeObjectURL(url)
          document.body.removeChild(a)
        }
      } catch (error) {
        console.error('Failed to print receipt:', error)
        toast.error(t('pos:receipt.printError'))
      } finally {
        setIsPrinting(false)
      }
    },
    [t]
  )

  const handleDownload = useCallback(
    async (receiptId: string) => {
      setIsDownloading(true)

      try {
        await downloadReceipt(receiptId)
        toast.success(t('pos:receipt.downloadSuccess'))
      } catch (error) {
        console.error('Failed to download receipt:', error)
        toast.error(t('pos:receipt.downloadError'))
      } finally {
        setIsDownloading(false)
      }
    },
    [t]
  )

  return {
    printReceipt: handlePrint,
    downloadReceipt: handleDownload,
    isPrinting,
    isDownloading,
  }
}
