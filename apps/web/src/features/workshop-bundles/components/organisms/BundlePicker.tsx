import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { borderColors, textColors, tokens } from '../../../../lib/designTokens'
import { useApplicableBundles } from '../../hooks/useBundles'
import type { ApplicableBundleData } from '../../types'
import { BundleSummaryCard } from '../molecules/BundleSummaryCard'
import { BundleExpandedPreview } from './BundleExpandedPreview'

interface BundlePickerProps {
  vehicleId?: string
  vehicleType?: string
  onSelect: (bundle: ApplicableBundleData) => void
  onClose: () => void
}

/**
 * Modal-style picker consumed by the future Work-Order UI (Spec B). Lists
 * applicable bundles for a vehicle context and surfaces a preview of
 * what the bundle will materialize into before the operator confirms.
 */
export function BundlePicker({
  vehicleId,
  vehicleType,
  onSelect,
  onClose,
}: BundlePickerProps) {
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

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="flex h-[80vh] w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl">
        <div className={`flex items-center justify-between border-b ${borderColors.light} p-4`}>
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>
            {t('picker.title')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className={tokens.modal.closeButton}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className={`border-b ${borderColors.light} p-4`}>
          <input
            type="search"
            placeholder={t('picker.searchPlaceholder')}
            value={search}
            onChange={(e) => { setSearch(e.target.value) }}
            className={`${tokens.input.base} mt-0`}
          />
        </div>

        <div className="flex flex-1 overflow-hidden">
          <div className={`w-1/2 overflow-y-auto border-r ${borderColors.light} p-4 space-y-2`}>
            {isLoading && (
              <div className={`text-sm ${textColors.tertiary}`}>{t('picker.loading')}</div>
            )}
            {bundles?.length === 0 && !isLoading && (
              <div className={`text-sm ${textColors.tertiary}`}>{t('picker.empty')}</div>
            )}
            {bundles?.map((bundle) => (
              <BundleSummaryCard
                key={bundle.id}
                bundle={bundle}
                onSelect={setPreview}
              />
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
