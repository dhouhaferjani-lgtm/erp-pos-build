import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { useApplicableBundles } from '@/features/workshop-bundles/hooks/useBundles'
import type { ApplicableBundleData } from '@/features/workshop-bundles/types'
import { BundleSummaryCard } from '@/features/workshop-bundles/components/molecules/BundleSummaryCard'
import { BundleExpandedPreview } from '@/features/workshop-bundles/components/organisms/BundleExpandedPreview'

/**
 * Controlled bundle picker — shown as a form-style trigger that opens a
 * modal list + preview. Moved from `features/workshop-bundles/components/
 * organisms/BundlePicker.tsx` in the Phase A.2 picker refactor; the
 * historical uncontrolled `onSelect`/`onClose` API is kept as a secondary
 * hook (`<BundlePickerModal>`) for call sites that open their own modal
 * (none today, but the bundle authoring UI in A.3 will use it when picking
 * nested bundles).
 */
interface BundlePickerProps {
  value: ApplicableBundleData | null
  onChange: (next: ApplicableBundleData | null) => void
  vehicleId?: string
  vehicleType?: string
  disabled?: boolean
  label?: string
  /** Bundles to exclude (e.g. the bundle being edited to prevent cycles). */
  excludeBundleIds?: string[]
}

interface BundlePickerModalProps {
  vehicleId?: string
  vehicleType?: string
  onSelect: (bundle: ApplicableBundleData) => void
  onClose: () => void
  excludeBundleIds?: string[]
}

/**
 * Modal-style bundle browser with a left list + right expansion preview.
 * Exported for callers that need to drive the open/close lifecycle
 * themselves. Most consumers should use `<BundlePicker>` instead.
 */
export function BundlePickerModal({
  vehicleId,
  vehicleType,
  onSelect,
  onClose,
  excludeBundleIds = [],
}: BundlePickerModalProps) {
  const { t } = useTranslation('workshop-bundles')
  const [search, setSearch] = useState('')
  const [preview, setPreview] = useState<ApplicableBundleData | null>(null)

  const params: { vehicle_id?: string; vehicle_type?: string; q?: string } = {}
  if (vehicleId !== undefined) {
    params.vehicle_id = vehicleId
  }
  if (vehicleType !== undefined) {
    params.vehicle_type = vehicleType
  }
  if (search !== '') {
    params.q = search
  }

  const { data: bundles, isLoading } = useApplicableBundles(params)
  const filtered = (bundles ?? []).filter((b) => !excludeBundleIds.includes(b.id))

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="flex h-[80vh] w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl">
        <div className={`flex items-center justify-between border-b ${borderColors.light} p-4`}>
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>{t('picker.title')}</h2>
          <button type="button" onClick={onClose} className={tokens.modal.closeButton}>
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className={`border-b ${borderColors.light} p-4`}>
          <input
            type="search"
            placeholder={t('picker.searchPlaceholder')}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
            }}
            className={`${tokens.input.base} mt-0`}
          />
        </div>

        <div className="flex flex-1 overflow-hidden">
          <div className={`w-1/2 overflow-y-auto border-r ${borderColors.light} p-4 space-y-2`}>
            {isLoading ? (
              <div className={`text-sm ${textColors.tertiary}`}>{t('picker.loading')}</div>
            ) : null}
            {filtered.length === 0 && !isLoading ? (
              <div className={`text-sm ${textColors.tertiary}`}>{t('picker.empty')}</div>
            ) : null}
            {filtered.map((bundle) => (
              <BundleSummaryCard key={bundle.id} bundle={bundle} onSelect={setPreview} />
            ))}
          </div>
          <div className="w-1/2 overflow-y-auto p-4">
            {preview === null ? (
              <div className={`text-sm ${textColors.tertiary}`}>{t('picker.selectPreview')}</div>
            ) : (
              <div className="space-y-3">
                <div>
                  <h3 className={`text-base font-medium ${textColors.primary}`}>{preview.name}</h3>
                  <p className={`text-xs ${textColors.tertiary}`}>{preview.code}</p>
                </div>
                <BundleExpandedPreview
                  bundleId={preview.id}
                  currency={preview.currency}
                  vehicleId={vehicleId}
                />
              </div>
            )}
          </div>
        </div>

        <div className={`flex items-center justify-end gap-2 border-t ${borderColors.light} p-4`}>
          <button
            type="button"
            onClick={onClose}
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
          >
            {t('picker.cancel')}
          </button>
          <button
            type="button"
            onClick={() => {
              if (preview !== null) {
                onSelect(preview)
              }
            }}
            disabled={preview === null}
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
          >
            {t('picker.confirm')}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * Controlled field-style bundle picker: shows the current selection (or a
 * placeholder trigger) and opens `<BundlePickerModal>` on click.
 */
export function BundlePicker({
  value,
  onChange,
  vehicleId,
  vehicleType,
  disabled = false,
  label,
  excludeBundleIds = [],
}: BundlePickerProps) {
  const { t } = useTranslation(['pickers', 'workshop-bundles'])
  const [open, setOpen] = useState(false)

  return (
    <div>
      {label !== undefined && label !== '' ? (
        <label className={tokens.label.base}>{label}</label>
      ) : null}

      {value !== null ? (
        <div
          className={`flex items-center gap-2 rounded-md border ${borderColors.default} bg-white px-3 py-2`}
        >
          <div className="min-w-0 flex-1">
            <div className={`truncate text-sm font-medium ${textColors.primary}`}>{value.name}</div>
            <div className={`truncate text-xs ${textColors.tertiary}`}>{value.code}</div>
          </div>
          <button
            type="button"
            disabled={disabled}
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
            onClick={() => {
              setOpen(true)
            }}
          >
            {t('pickers:bundle.changeBundle')}
          </button>
          <button
            type="button"
            disabled={disabled}
            aria-label={t('pickers:common.clear')}
            className={`${textColors.tertiary} hover:${textColors.primary}`}
            onClick={() => {
              onChange(null)
            }}
          >
            <X className="h-4 w-4" aria-hidden />
          </button>
        </div>
      ) : (
        <button
          type="button"
          disabled={disabled}
          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md} w-full justify-between`}
          onClick={() => {
            setOpen(true)
          }}
        >
          <span className={textColors.tertiary}>{t('pickers:bundle.triggerPlaceholder')}</span>
          <span className={textColors.brand}>+</span>
        </button>
      )}

      {open ? (
        <BundlePickerModal
          {...(vehicleId !== undefined ? { vehicleId } : {})}
          {...(vehicleType !== undefined ? { vehicleType } : {})}
          excludeBundleIds={excludeBundleIds}
          onClose={() => {
            setOpen(false)
          }}
          onSelect={(bundle) => {
            onChange(bundle)
            setOpen(false)
          }}
        />
      ) : null}
    </div>
  )
}
