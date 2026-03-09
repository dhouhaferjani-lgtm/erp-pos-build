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

        // Use a hidden iframe instead of window.open (more reliable, avoids popup blockers)
        const iframe = document.createElement('iframe')
        iframe.style.position = 'fixed'
        iframe.style.width = '1px'
        iframe.style.height = '1px'
        iframe.style.opacity = '0'
        iframe.style.left = '-9999px'
        iframe.style.top = '0'
        iframe.style.border = 'none'
        iframe.src = url
        document.body.appendChild(iframe)

        iframe.onload = () => {
          // Delay to let the PDF viewer plugin initialise inside the iframe
          setTimeout(() => {
            try {
              iframe.contentWindow?.focus()
              iframe.contentWindow?.print()
            } catch {
              // Cross-origin or plugin restriction — fall back to new tab
              window.open(url, '_blank')
            }

            // Clean up after a generous delay
            setTimeout(() => {
              try {
                document.body.removeChild(iframe)
              } catch {
                // already removed
              }
              window.URL.revokeObjectURL(url)
            }, 60_000)
          }, 500)
        }

        toast.success(t('pos:receipt.printSuccess'))
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
