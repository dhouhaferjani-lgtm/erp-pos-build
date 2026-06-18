import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { Button, Input, Select } from '@/components/atoms'
import { tokens, textColors } from '@/lib/designTokens'
import { getErrorMessage } from '@/lib/api'
import { useLabelFormats } from '../hooks/useLabels'
import {
  prepareVariantLabels,
  downloadVariantLabelsPdf,
  type LabelItem,
  type SkippedLabel,
} from '../api/labelApi'

export interface VariantLabelDialogVariant {
  id: string
  name_suffix: string
}

interface VariantLabelDialogProps {
  open: boolean
  onClose: () => void
  variants: VariantLabelDialogVariant[]
  productName?: string
}

/**
 * Two-step variant label printing dialog:
 *   1. `prepare` resolves barcodes server-side and reports which variants were
 *      skipped (and why). We render the skipped list inline.
 *   2. If anything is `ready`, `pdf` renders the sheet and we trigger a browser
 *      download of the returned blob. If nothing is ready we stop and show a
 *      "nothing to print" notice — no PDF request is made.
 *
 * The dialog is pinned to FIXED dimensions (per the project's fixed-modal UX
 * rule) with an internal scroll region, since the base Modal only constrains
 * max-width.
 */
export function VariantLabelDialog({
  open,
  onClose,
  variants,
  productName,
}: VariantLabelDialogProps) {
  const { t } = useTranslation()
  const { data: formats } = useLabelFormats()

  const [format, setFormat] = useState('')
  const [quantities, setQuantities] = useState<Record<string, string>>({})
  const [startCell, setStartCell] = useState('')
  const [skipped, setSkipped] = useState<SkippedLabel[] | null>(null)
  const [readyCount, setReadyCount] = useState<number | null>(null)
  const [nothingPrintable, setNothingPrintable] = useState(false)
  const [isGenerating, setIsGenerating] = useState(false)

  // Default the format select to the first available format once loaded.
  const effectiveFormat = useMemo(() => {
    if (format) return format
    return formats?.[0]?.key ?? ''
  }, [format, formats])

  const quantityFor = (variantId: string): number => {
    const raw = (quantities[variantId] ?? '').trim()
    if (raw === '') return 1
    const parsed = Number.parseInt(raw, 10)
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 1
  }

  const buildItems = (): LabelItem[] =>
    variants.map((v) => ({ variant_id: v.id, quantity: quantityFor(v.id) }))

  const triggerDownload = (blob: Blob) => {
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'variant-labels.pdf'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
  }

  const handleConfirm = async () => {
    setIsGenerating(true)
    setSkipped(null)
    setReadyCount(null)
    setNothingPrintable(false)
    try {
      const prepared = await prepareVariantLabels(buildItems())
      const ready = prepared.data.ready
      setSkipped(prepared.meta.skipped)
      setReadyCount(ready.length)

      if (ready.length === 0) {
        setNothingPrintable(true)
        return
      }

      const startCellValue = startCell.trim()
      const blob = await downloadVariantLabelsPdf({
        format: effectiveFormat,
        items: ready.map((r) => ({
          variant_id: r.variant_id,
          quantity: r.quantity,
        })),
        ...(startCellValue !== ''
          ? { start_cell: Number.parseInt(startCellValue, 10) }
          : {}),
      })
      triggerDownload(blob)
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setIsGenerating(false)
    }
  }

  const handleClose = () => {
    setSkipped(null)
    setReadyCount(null)
    setNothingPrintable(false)
    onClose()
  }

  return (
    <Modal
      isOpen={open}
      onClose={handleClose}
      title={
        productName
          ? t('catalog:labels.titleFor', { name: productName })
          : t('catalog:labels.title')
      }
      size="lg"
      className="flex h-[36rem] w-[42rem] flex-col"
    >
      <ModalContent className="flex-1 overflow-y-auto">
        <div className="space-y-2">
          <label
            htmlFor="label-format"
            className={`block text-sm font-medium ${textColors.secondary}`}
          >
            {t('catalog:labels.format')}
          </label>
          <Select
            id="label-format"
            aria-label={t('catalog:labels.format')}
            value={effectiveFormat}
            onChange={(e) => {
              setFormat(e.target.value)
            }}
          >
            {(formats ?? []).map((f) => (
              <option key={f.key} value={f.key}>
                {f.label}
              </option>
            ))}
          </Select>
        </div>

        <div className="space-y-2">
          <label
            htmlFor="label-start-cell"
            className={`block text-sm font-medium ${textColors.secondary}`}
          >
            {t('catalog:labels.startCell')}
          </label>
          <Input
            id="label-start-cell"
            type="number"
            min={1}
            aria-label={t('catalog:labels.startCell')}
            value={startCell}
            onChange={(e) => {
              setStartCell(e.target.value)
            }}
          />
        </div>

        <div className="space-y-2">
          <p className={`text-sm font-medium ${textColors.secondary}`}>
            {t('catalog:labels.quantity')}
          </p>
          <ul className="space-y-2">
            {variants.map((v) => (
              <li key={v.id} className="flex items-center justify-between gap-3">
                <span className={`text-sm ${textColors.primary}`}>
                  {v.name_suffix}
                </span>
                <Input
                  type="number"
                  min={1}
                  className="w-24"
                  aria-label={t('catalog:labels.quantityFor', {
                    name: v.name_suffix,
                  })}
                  value={quantities[v.id] ?? '1'}
                  onChange={(e) => {
                    setQuantities((prev) => ({ ...prev, [v.id]: e.target.value }))
                  }}
                />
              </li>
            ))}
          </ul>
        </div>

        {readyCount !== null ? (
          <p className={`text-sm ${textColors.secondary}`}>
            {t('catalog:labels.readyCount', { count: readyCount })}
          </p>
        ) : null}

        {skipped && skipped.length > 0 ? (
          <div className={`${tokens.alert.base} ${tokens.alert.warning}`}>
            <p className="text-sm font-medium">
              {t('catalog:labels.skipped', { count: skipped.length })}
            </p>
            <ul className="mt-1 list-disc ps-5 text-sm">
              {skipped.map((s) => (
                <li key={s.variant_id}>
                  {t(`catalog:labels.skippedReason.${s.reason}`, {
                    defaultValue: t('catalog:labels.skippedReason.error'),
                  })}
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        {nothingPrintable ? (
          <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
            {t('catalog:labels.nothingPrintable')}
          </div>
        ) : null}
      </ModalContent>

      <ModalFooter>
        <Button type="button" variant="secondary" onClick={handleClose}>
          {t('catalog:labels.cancel')}
        </Button>
        <Button
          type="button"
          onClick={() => {
            void handleConfirm()
          }}
          disabled={isGenerating || variants.length === 0 || effectiveFormat === ''}
        >
          {isGenerating
            ? t('catalog:labels.generating')
            : t('catalog:labels.download')}
        </Button>
      </ModalFooter>
    </Modal>
  )
}
