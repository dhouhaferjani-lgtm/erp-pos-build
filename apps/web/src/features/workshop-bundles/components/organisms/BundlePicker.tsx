import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
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
        <div className="flex items-center justify-between border-b border-gray-200 p-4">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('picker.title')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="border-b border-gray-200 p-4">
          <input
            type="search"
            placeholder={t('picker.searchPlaceholder')}
            value={search}
            onChange={(e) => { setSearch(e.target.value) }}
            className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        <div className="flex flex-1 overflow-hidden">
          <div className="w-1/2 overflow-y-auto border-r border-gray-200 p-4 space-y-2">
            {isLoading && (
              <div className="text-sm text-gray-500">{t('picker.loading')}</div>
            )}
            {bundles?.length === 0 && !isLoading && (
              <div className="text-sm text-gray-500">{t('picker.empty')}</div>
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
              <div className="text-sm text-gray-500">{t('picker.selectPreview')}</div>
            ) : (
              <div className="space-y-3">
                <div>
                  <h3 className="text-base font-medium text-gray-900">{preview.name}</h3>
                  <p className="text-xs text-gray-500">{preview.code}</p>
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

        <div className="flex items-center justify-end gap-2 border-t border-gray-200 p-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
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
            className="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:bg-blue-300"
          >
            {t('picker.confirm')}
          </button>
        </div>
      </div>
    </div>
  )
}
