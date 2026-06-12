import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { tokens } from '../../../lib/designTokens'

export interface MarginSettings {
  defaultTargetMargin: number
  defaultMinimumMargin: number
  requireApprovalBelowMinimum: boolean
  blockSalesAtLoss: boolean
}

export interface InventorySettingsProps {
  settings: MarginSettings
  onSave: (settings: MarginSettings) => Promise<void>
  disabled?: boolean
}

export function InventorySettings({
  settings,
  onSave,
  disabled = false,
}: InventorySettingsProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const [formData, setFormData] = useState<MarginSettings>(settings)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setIsSaving(true)

    try {
      await onSave(formData)
    } catch (err) {
      setError(err instanceof Error ? err.message : t('inventory:settings.saveError'))
    } finally {
      setIsSaving(false)
    }
  }

  const hasChanges =
    formData.defaultTargetMargin !== settings.defaultTargetMargin ||
    formData.defaultMinimumMargin !== settings.defaultMinimumMargin ||
    formData.requireApprovalBelowMinimum !== settings.requireApprovalBelowMinimum ||
    formData.blockSalesAtLoss !== settings.blockSalesAtLoss

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
      <h2 className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
        {t('inventory:pricing.settings.title')}
      </h2>

      {error && (
        <div className={`mb-4 rounded-lg p-3 text-sm dark:bg-red-900/20 dark:text-red-400 ${tokens.alert.error}`}>
          {error}
        </div>
      )}

      <form onSubmit={(e) => { void handleSubmit(e) }} className="space-y-6">
        {/* Default Target Margin */}
        <div>
          <label
            htmlFor="defaultTargetMargin"
            className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
          >
            {t('inventory:pricing.settings.defaultTarget')}
          </label>
          <div className="relative w-32">
            <input
              type="number"
              id="defaultTargetMargin"
              value={formData.defaultTargetMargin}
              onChange={(e) => {
                setFormData((prev) => ({
                  ...prev,
                  defaultTargetMargin: parseFloat(e.target.value) || 0,
                }))
              }}
              disabled={disabled || isSaving}
              step="0.1"
              min="0"
              max="100"
              className="w-full rounded-md border border-gray-300 px-3 py-2 pe-8 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
            />
            <span className="absolute end-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">
              %
            </span>
          </div>
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {t('inventory:pricing.settings.targetDescription')}
          </p>
        </div>

        {/* Default Minimum Margin */}
        <div>
          <label
            htmlFor="defaultMinimumMargin"
            className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
          >
            {t('inventory:pricing.settings.defaultMinimum')}
          </label>
          <div className="relative w-32">
            <input
              type="number"
              id="defaultMinimumMargin"
              value={formData.defaultMinimumMargin}
              onChange={(e) => {
                setFormData((prev) => ({
                  ...prev,
                  defaultMinimumMargin: parseFloat(e.target.value) || 0,
                }))
              }}
              disabled={disabled || isSaving}
              step="0.1"
              min="0"
              max="100"
              className="w-full rounded-md border border-gray-300 px-3 py-2 pe-8 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
            />
            <span className="absolute end-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">
              %
            </span>
          </div>
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {t('inventory:pricing.settings.minimumDescription')}
          </p>
        </div>

        {/* Require Approval Below Minimum */}
        <div className="flex items-start gap-3">
          <input
            type="checkbox"
            id="requireApprovalBelowMinimum"
            checked={formData.requireApprovalBelowMinimum}
            onChange={(e) => {
              setFormData((prev) => ({
                ...prev,
                requireApprovalBelowMinimum: e.target.checked,
              }))
            }}
            disabled={disabled || isSaving}
            className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label
              htmlFor="requireApprovalBelowMinimum"
              className="block text-sm font-medium text-gray-700 dark:text-gray-300"
            >
              {t('inventory:pricing.settings.requireApprovalBelow')}
            </label>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t('inventory:pricing.settings.requireApprovalDescription')}
            </p>
          </div>
        </div>

        {/* Block Sales at Loss */}
        <div className="flex items-start gap-3">
          <input
            type="checkbox"
            id="blockSalesAtLoss"
            checked={formData.blockSalesAtLoss}
            onChange={(e) => {
              setFormData((prev) => ({
                ...prev,
                blockSalesAtLoss: e.target.checked,
              }))
            }}
            disabled={disabled || isSaving}
            className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label
              htmlFor="blockSalesAtLoss"
              className="block text-sm font-medium text-gray-700 dark:text-gray-300"
            >
              {t('inventory:pricing.settings.blockSalesAtLoss')}
            </label>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t('inventory:pricing.settings.blockSalesDescription')}
            </p>
          </div>
        </div>

        {/* Actions */}
        <div className="flex items-center justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
          <button
            type="button"
            onClick={() => { setFormData(settings) }}
            disabled={disabled || isSaving || !hasChanges}
            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
          >
            {t('common:actions.reset')}
          </button>
          <button
            type="submit"
            disabled={disabled || isSaving || !hasChanges}
            className={`flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium disabled:opacity-50 ${tokens.button.primary}`}
          >
            {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
            {t('inventory:pricing.settings.saveChanges')}
          </button>
        </div>
      </form>
    </div>
  )
}

export default InventorySettings
