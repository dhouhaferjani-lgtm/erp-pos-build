import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { useCreateWithholdingRule, useUpdateWithholdingRule } from '../hooks/useWithholding'
import type { WithholdingRule, CreateWithholdingRuleRequest } from '../types'

interface WithholdingRuleFormModalProps {
  isOpen: boolean
  onClose: () => void
  rule?: WithholdingRule | null
  countryCode?: string
}

export function WithholdingRuleFormModal({
  isOpen,
  onClose,
  rule,
  countryCode = 'TN',
}: WithholdingRuleFormModalProps) {
  const { t } = useTranslation(['withholding', 'common'])
  const createMutation = useCreateWithholdingRule()
  const updateMutation = useUpdateWithholdingRule()

  const [formData, setFormData] = useState<CreateWithholdingRuleRequest>({
    country_code: countryCode,
    code: '',
    name: '',
    description: '',
    transaction_type: undefined,
    partner_tax_status: undefined,
    min_amount: '',
    rate: 0,
    effective_from: new Date().toISOString().split('T')[0],
    effective_to: '',
    is_active: true,
  })

  // Load rule data when editing
  useEffect(() => {
    if (rule) {
      setFormData({
        country_code: rule.country_code,
        code: rule.code,
        name: rule.name,
        description: rule.description || '',
        transaction_type: rule.transaction_type || undefined,
        partner_tax_status: rule.partner_tax_status || undefined,
        min_amount: rule.min_amount || '',
        rate: rule.rate_percentage,
        effective_from: rule.effective_from.split('T')[0],
        effective_to: rule.effective_to?.split('T')[0] || '',
        is_active: rule.is_active,
      })
    } else {
      // Reset form for new rule
      setFormData({
        country_code: countryCode,
        code: '',
        name: '',
        description: '',
        transaction_type: undefined,
        partner_tax_status: undefined,
        min_amount: '',
        rate: 0,
        effective_from: new Date().toISOString().split('T')[0],
        effective_to: '',
        is_active: true,
      })
    }
  }, [rule, countryCode])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    try {
      if (rule) {
        await updateMutation.mutateAsync({
          id: rule.id,
          data: formData,
        })
      } else {
        await createMutation.mutateAsync(formData)
      }
      onClose()
    } catch (error) {
      // Error handled by mutation hooks
    }
  }

  const handleChange = (field: keyof CreateWithholdingRuleRequest, value: any) => {
    setFormData((prev) => ({
      ...prev,
      [field]: value === '' ? undefined : value,
    }))
  }

  if (!isOpen) return null

  const isLoading = createMutation.isPending || updateMutation.isPending

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      <div className="flex min-h-screen items-center justify-center px-4">
        {/* Overlay */}
        <div
          className="fixed inset-0 bg-black/50 transition-opacity"
          onClick={onClose}
        />

        {/* Modal */}
        <div className="relative w-full max-w-2xl rounded-lg bg-white shadow-xl">
          {/* Header */}
          <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h2 className="text-lg font-semibold text-gray-900">
              {rule ? t('rules.editRule') : t('rules.createRule')}
            </h2>
            <button
              onClick={onClose}
              className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
            >
              <X className="h-5 w-5" />
            </button>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="p-6 space-y-6">
            {/* Basic Info */}
            <div className="space-y-4">
              <h3 className="text-sm font-medium text-gray-900">
                {t('rules.basicInfo')}
              </h3>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    {t('rules.code')} *
                  </label>
                  <input
                    type="text"
                    value={formData.code}
                    onChange={(e) => handleChange('code', e.target.value)}
                    required
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                    placeholder="e.g., TN_PROF_10"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    {t('rules.ratePercentage')} *
                  </label>
                  <input
                    type="number"
                    value={formData.rate}
                    onChange={(e) => handleChange('rate', parseFloat(e.target.value))}
                    required
                    min="0"
                    max="100"
                    step="0.01"
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                    placeholder="10.00"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  {t('rules.name')} *
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => handleChange('name', e.target.value)}
                  required
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                  placeholder={t('rules.namePlaceholder')}
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  {t('rules.description')}
                </label>
                <textarea
                  value={formData.description}
                  onChange={(e) => handleChange('description', e.target.value)}
                  rows={2}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                  placeholder={t('rules.descriptionPlaceholder')}
                />
              </div>
            </div>

            {/* Conditions */}
            <div className="space-y-4">
              <h3 className="text-sm font-medium text-gray-900">
                {t('rules.conditions')}
              </h3>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    {t('rules.transactionType')}
                  </label>
                  <select
                    value={formData.transaction_type || ''}
                    onChange={(e) => handleChange('transaction_type', e.target.value)}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                  >
                    <option value="">{t('common:all')}</option>
                    <option value="services">{t('transactionTypes.services')}</option>
                    <option value="goods">{t('transactionTypes.goods')}</option>
                    <option value="professional_services">{t('transactionTypes.professional_services')}</option>
                    <option value="rent">{t('transactionTypes.rent')}</option>
                  </select>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    {t('rules.partnerTaxStatus')}
                  </label>
                  <select
                    value={formData.partner_tax_status || ''}
                    onChange={(e) => handleChange('partner_tax_status', e.target.value)}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                  >
                    <option value="">{t('common:all')}</option>
                    <option value="REGISTERED">{t('taxStatuses.registered')}</option>
                    <option value="NON_REGISTERED">{t('taxStatuses.nonRegistered')}</option>
                    <option value="EXEMPT">{t('taxStatuses.exempt')}</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  {t('rules.minAmount')}
                </label>
                <input
                  type="number"
                  value={formData.min_amount}
                  onChange={(e) => handleChange('min_amount', e.target.value)}
                  min="0"
                  step="0.001"
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                  placeholder="0.000"
                />
                <p className="mt-1 text-xs text-gray-500">
                  {t('rules.minAmountHelp')}
                </p>
              </div>
            </div>

            {/* Validity Period */}
            <div className="space-y-4">
              <h3 className="text-sm font-medium text-gray-900">
                {t('rules.validityPeriod')}
              </h3>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    {t('rules.effectiveFrom')} *
                  </label>
                  <input
                    type="date"
                    value={formData.effective_from}
                    onChange={(e) => handleChange('effective_from', e.target.value)}
                    required
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    {t('rules.effectiveTo')}
                  </label>
                  <input
                    type="date"
                    value={formData.effective_to}
                    onChange={(e) => handleChange('effective_to', e.target.value)}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                  />
                  <p className="mt-1 text-xs text-gray-500">
                    {t('rules.effectiveToHelp')}
                  </p>
                </div>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  checked={formData.is_active}
                  onChange={(e) => handleChange('is_active', e.target.checked)}
                  className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <label htmlFor="is_active" className="text-sm text-gray-700">
                  {t('rules.isActive')}
                </label>
              </div>
            </div>

            {/* Actions */}
            <div className="flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
              <button
                type="button"
                onClick={onClose}
                disabled={isLoading}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              >
                {t('common:cancel')}
              </button>
              <button
                type="submit"
                disabled={isLoading}
                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {isLoading ? t('common:saving') : rule ? t('common:save') : t('common:create')}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
