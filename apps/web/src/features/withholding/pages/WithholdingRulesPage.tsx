import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Edit2, Trash2, CheckCircle, XCircle, AlertCircle } from 'lucide-react'
import { useWithholdingRules, useDeleteWithholdingRule } from '../hooks/useWithholding'
import { WithholdingRuleFormModal } from '../components/WithholdingRuleFormModal'
import type { WithholdingRule } from '../types'

export function WithholdingRulesPage() {
  const { t } = useTranslation(['withholding', 'common'])
  const [selectedCountry, setSelectedCountry] = useState('TN')
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingRule, setEditingRule] = useState<WithholdingRule | null>(null)

  const { data: rules, isLoading, isError } = useWithholdingRules(selectedCountry)
  const deleteMutation = useDeleteWithholdingRule()

  const handleCreate = () => {
    setEditingRule(null)
    setIsModalOpen(true)
  }

  const handleEdit = (rule: WithholdingRule) => {
    setEditingRule(rule)
    setIsModalOpen(true)
  }

  const handleDelete = async (rule: WithholdingRule) => {
    if (!confirm(t('rules.confirmDelete', { name: rule.name }))) {
      return
    }

    try {
      await deleteMutation.mutateAsync(rule.id)
    } catch (error) {
      // Error handled by mutation hook
    }
  }

  const handleCloseModal = () => {
    setIsModalOpen(false)
    setEditingRule(null)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-gray-500">{t('common:loading')}</div>
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <AlertCircle className="mx-auto h-12 w-12 text-red-500" />
          <p className="mt-2 text-sm text-gray-600">{t('common:error')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('rules.title')}</h1>
          <p className="mt-1 text-sm text-gray-600">{t('rules.subtitle')}</p>
        </div>
        <button
          type="button"
          onClick={handleCreate}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('rules.addRule')}
        </button>
      </div>

      {/* Country Filter */}
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <label className="block text-sm font-medium text-gray-700 mb-2">
          {t('rules.countryFilter')}
        </label>
        <select
          value={selectedCountry}
          onChange={(e) => setSelectedCountry(e.target.value)}
          className="block w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
        >
          <option value="TN">{t('countries.tunisia')}</option>
          <option value="FR">{t('countries.france')}</option>
          <option value="MA">{t('countries.morocco')}</option>
          <option value="DZ">{t('countries.algeria')}</option>
        </select>
      </div>

      {/* Rules Table */}
      <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
        {!rules || rules.length === 0 ? (
          <div className="p-8 text-center">
            <AlertCircle className="mx-auto h-12 w-12 text-gray-400" />
            <p className="mt-2 text-sm text-gray-600">{t('rules.noRules')}</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('rules.code')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('rules.name')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('rules.transactionType')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('rules.partnerRegime')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('rules.rate')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('rules.minAmount')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('rules.status')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('common:actions')}
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {rules.map((rule: WithholdingRule) => (
                  <tr key={rule.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span className="text-sm font-mono text-gray-900">{rule.code}</span>
                    </td>
                    <td className="px-4 py-3">
                      <span className="text-sm text-gray-900">{rule.name}</span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span className="text-sm text-gray-600">
                        {rule.transaction_type ? t(`transactionTypes.${rule.transaction_type}`) : '-'}
                      </span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span className="text-sm text-gray-600">
                        {rule.partner_tax_regime ? t(`taxRegimes.${rule.partner_tax_regime}`) : '-'}
                      </span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <span className="text-sm font-mono font-semibold text-gray-900">
                        {rule.rate_percentage}%
                      </span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <span className="text-sm font-mono text-gray-600">
                        {rule.min_amount ? `${parseFloat(rule.min_amount).toFixed(0)} ${rule.currency || 'TND'}` : '-'}
                      </span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-center">
                      {rule.is_active ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                          <CheckCircle className="h-3 w-3" />
                          {t('common:active')}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800">
                          <XCircle className="h-3 w-3" />
                          {t('common:inactive')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-center">
                      <div className="flex items-center justify-center gap-1">
                        <button
                          type="button"
                          onClick={() => handleEdit(rule)}
                          className="inline-flex items-center justify-center rounded p-1 text-blue-600 hover:bg-blue-100 hover:text-blue-900"
                          title={t('common:edit')}
                        >
                          <Edit2 className="h-4 w-4" />
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDelete(rule)}
                          className="inline-flex items-center justify-center rounded p-1 text-red-600 hover:bg-red-100 hover:text-red-900"
                          title={t('common:delete')}
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Info Panel */}
      <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
        <h3 className="text-sm font-medium text-blue-900">{t('rules.infoTitle')}</h3>
        <p className="mt-1 text-sm text-blue-700">{t('rules.infoText')}</p>
      </div>

      {/* Rule Form Modal */}
      <WithholdingRuleFormModal
        isOpen={isModalOpen}
        onClose={handleCloseModal}
        rule={editingRule}
        countryCode={selectedCountry}
      />
    </div>
  )
}
