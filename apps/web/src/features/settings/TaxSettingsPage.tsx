import { useState } from 'react'
import { Link } from 'react-router-dom'
import { EmptyState } from '../../components/molecules/EmptyState/EmptyState'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Save, Plus, Pencil, Trash2, GripVertical } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPut, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useCompany } from '../../hooks/useCompany'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/ui/Tabs'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import {
  useTaxConfigurations,
  useDeleteTaxConfiguration,
} from './hooks/useTaxConfigurations'
import { TaxConfigFormModal } from '../../components/organisms'
import type {
  TaxConfiguration,
  CompanyTaxStatus,
} from './types/tax'

interface TaxSettings {
  default_tax_rate: string | null
  fiscal_year_start_month: number
  tax_status: CompanyTaxStatus
  vat_registration_number: string | null
}

interface CompanyResponse {
  id: string
  name: string
  default_tax_rate: string | null
  fiscal_year_start_month: number
  tax_status: CompanyTaxStatus
  vat_registration_number: string | null
}

const MONTH_VALUES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function TaxSettingsPage() {
  const { t } = useTranslation(['settings', 'common', 'sales'])
  const { currentCompany } = useCompany()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [activeTab, setActiveTab] = useState('profile')
  const [formData, setFormData] = useState<TaxSettings>({
    default_tax_rate: null,
    fiscal_year_start_month: 1,
    tax_status: 'NON_REGISTERED',
    vat_registration_number: null,
  })
  const [isDirty, setIsDirty] = useState(false)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingTax, setEditingTax] = useState<TaxConfiguration | null>(null)
  const [deletingTaxId, setDeletingTaxId] = useState<string | null>(null)

  // Fetch company settings
  const { isLoading: isLoadingCompany } = useQuery({
    queryKey: tenantScopedKey(['company', 'tax-settings', currentCompany?.id]),
    queryFn: async () => {
      if (!currentCompany?.id) return null
      const response = await api.get<{ data: CompanyResponse }>(`/companies/${currentCompany.id}`)
      const company = response.data.data

      setFormData({
        default_tax_rate: company.default_tax_rate,
        fiscal_year_start_month: company.fiscal_year_start_month,
        tax_status: company.tax_status,
        vat_registration_number: company.vat_registration_number,
      })

      return company
    },
    enabled: !!currentCompany?.id && tenantId !== null && companyId !== null,
  })

  // Tax configurations
  const { data: taxConfigurations = [], isLoading: isLoadingTaxes } = useTaxConfigurations()
  const deleteTax = useDeleteTaxConfiguration()

  // Update company settings
  const updateMutation = useMutation({
    mutationFn: async (data: TaxSettings) => {
      if (!currentCompany?.id) throw new Error('No company selected')
      return apiPut<CompanyResponse>(`/companies/${currentCompany.id}`, data)
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('company', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('companies', tenantId, companyId),
        }),
      ])
      setIsDirty(false)
      toast.success(t('common:saveSuccess'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleChange = (field: keyof TaxSettings, value: string | number | null) => {
    setFormData(prev => ({ ...prev, [field]: value }))
    setIsDirty(true)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    updateMutation.mutate(formData)
  }

  const handleCreateTax = () => {
    setEditingTax(null)
    setIsModalOpen(true)
  }

  const handleEditTax = (tax: TaxConfiguration) => {
    setEditingTax(tax)
    setIsModalOpen(true)
  }

  const handleDeleteTax = async () => {
    if (!deletingTaxId) return
    try {
      await deleteTax.mutateAsync(deletingTaxId)
      toast.success(t('settings:tax.configurations.messages.deleted'))
      setDeletingTaxId(null)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const isLoading = isLoadingCompany || isLoadingTaxes

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
          <p className="mt-4 text-gray-600">{t('common:loading')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/settings"
          className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
        >
          <ArrowLeft className="h-4 w-4 me-1" />
          {t('common:back')}
        </Link>

        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">{t('settings:tax.configurations.pageTitle')}</h1>
            <p className="mt-2 text-sm text-gray-600">{t('settings:tax.configurations.description')}</p>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="profile" value={activeTab} onChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="profile">{t('settings:tax.configurations.tabs.profile')}</TabsTrigger>
          <TabsTrigger value="taxes">{t('settings:tax.configurations.tabs.taxes')}</TabsTrigger>
        </TabsList>

        {/* Company Tax Profile Tab */}
        <TabsContent value="profile" className="mt-6">
          <form onSubmit={handleSubmit}>
            <div className="bg-white shadow sm:rounded-lg">
              {/* Tax Status */}
              <div className="px-6 py-6 border-b border-gray-200">
                <h2 className="text-lg font-medium text-gray-900 mb-4">
                  {t('settings:tax.configurations.profile.title')}
                </h2>
                <p className="text-sm text-gray-600 mb-4">
                  {t('settings:tax.configurations.profile.description')}
                </p>

                <div className="space-y-4 max-w-md">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">
                      {t('settings:tax.configurations.profile.title')}
                    </label>
                    <div className="space-y-2">
                      <label className="flex items-start">
                        <input
                          type="radio"
                          name="tax_status"
                          value="REGISTERED"
                          checked={formData.tax_status === 'REGISTERED'}
                          onChange={(e) => { handleChange('tax_status', e.target.value as CompanyTaxStatus); }}
                          className="mt-0.5 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                        />
                        <span className="ms-3">
                          <span className="block text-sm font-medium text-gray-900">
                            {t('settings:tax.configurations.profile.registered')}
                          </span>
                          <span className="block text-sm text-gray-500">
                            {t('settings:tax.configurations.profile.registeredDescription')}
                          </span>
                        </span>
                      </label>
                      <label className="flex items-start">
                        <input
                          type="radio"
                          name="tax_status"
                          value="NON_REGISTERED"
                          checked={formData.tax_status === 'NON_REGISTERED'}
                          onChange={(e) => { handleChange('tax_status', e.target.value as CompanyTaxStatus); }}
                          className="mt-0.5 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                        />
                        <span className="ms-3">
                          <span className="block text-sm font-medium text-gray-900">
                            {t('settings:tax.configurations.profile.nonRegistered')}
                          </span>
                          <span className="block text-sm text-gray-500">
                            {t('settings:tax.configurations.profile.nonRegisteredDescription')}
                          </span>
                        </span>
                      </label>
                    </div>
                  </div>

                  {formData.tax_status === 'REGISTERED' && (
                    <div>
                      <label htmlFor="vat_number" className="block text-sm font-medium text-gray-700 mb-1">
                        {t('settings:tax.configurations.profile.vatNumber')}
                      </label>
                      <input
                        type="text"
                        id="vat_number"
                        value={formData.vat_registration_number ?? ''}
                        onChange={(e) => { handleChange('vat_registration_number', e.target.value || null); }}
                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        placeholder={t('settings:tax.configurations.profile.vatNumberPlaceholder')}
                      />
                    </div>
                  )}
                </div>
              </div>

              {/* Default Tax Rate */}
              <div className="px-6 py-6 border-b border-gray-200">
                <h2 className="text-lg font-medium text-gray-900 mb-4">{t('settings:tax.defaultTaxRate.title')}</h2>
                <p className="text-sm text-gray-600 mb-4">{t('settings:tax.defaultTaxRate.description')}</p>

                <div className="max-w-xs">
                  <label htmlFor="default_tax_rate" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('settings:tax.defaultTaxRate.label')}
                  </label>
                  <div className="relative">
                    <input
                      type="number"
                      id="default_tax_rate"
                      step="0.01"
                      min="0"
                      max="100"
                      value={formData.default_tax_rate ?? ''}
                      onChange={(e) => { handleChange('default_tax_rate', e.target.value || null); }}
                      className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm pe-8"
                      placeholder="19.00"
                    />
                    <div className="absolute inset-y-0 end-0 pe-3 flex items-center pointer-events-none">
                      <span className="text-gray-500 sm:text-sm">%</span>
                    </div>
                  </div>
                  <p className="mt-2 text-xs text-gray-500">{t('settings:tax.defaultTaxRate.help')}</p>
                </div>
              </div>

              {/* Fiscal Year */}
              <div className="px-6 py-6">
                <h2 className="text-lg font-medium text-gray-900 mb-4">{t('settings:tax.fiscalYear.title')}</h2>
                <p className="text-sm text-gray-600 mb-4">{t('settings:tax.fiscalYear.description')}</p>

                <div className="max-w-xs">
                  <label htmlFor="fiscal_year_start_month" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('settings:tax.fiscalYear.label')}
                  </label>
                  <select
                    id="fiscal_year_start_month"
                    value={formData.fiscal_year_start_month}
                    onChange={(e) => { handleChange('fiscal_year_start_month', parseInt(e.target.value)); }}
                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                  >
                    {MONTH_VALUES.map(value => (
                      <option key={value} value={value}>
                        {t(`settings:tax.months.${value}`)}
                      </option>
                    ))}
                  </select>
                  <p className="mt-2 text-xs text-gray-500">{t('settings:tax.fiscalYear.help')}</p>
                </div>
              </div>
            </div>

            {/* Actions */}
            <div className="mt-6 flex items-center justify-end gap-3">
              <Link
                to="/settings"
                className="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
              >
                {t('common:cancel')}
              </Link>
              <button
                type="submit"
                disabled={!isDirty || updateMutation.isPending}
                className="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <Save className="h-4 w-4" />
                {updateMutation.isPending ? t('common:saving') : t('common:save')}
              </button>
            </div>
          </form>
        </TabsContent>

        {/* Tax Configurations Tab */}
        <TabsContent value="taxes" className="mt-6">
          <div className="space-y-6">
            {/* Add Tax Button */}
            <div className="flex justify-end">
              <button
                type="button"
                onClick={handleCreateTax}
                className="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium"
              >
                <Plus className="h-4 w-4" />
                {t('settings:tax.configurations.addButton')}
              </button>
            </div>

            {/* Tax List */}
            {taxConfigurations.length === 0 ? (
              <div className="bg-white rounded-lg border border-gray-200">
                <EmptyState title={t('settings:tax.configurations.table.noData')} />
              </div>
            ) : (
              <div className="bg-white shadow sm:rounded-lg overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                        {t('settings:tax.configurations.table.name')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                        {t('settings:tax.configurations.table.type')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                        {t('settings:tax.configurations.table.rate')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                        {t('settings:tax.configurations.table.appliesTo')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                        {t('settings:tax.configurations.table.active')}
                      </th>
                      <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                        {t('settings:tax.configurations.table.actions')}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {taxConfigurations.map((tax) => (
                      <tr key={tax.id}>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center">
                            <GripVertical className="h-4 w-4 text-gray-400 me-2" />
                            <div>
                              <div className="text-sm font-medium text-gray-900">{tax.name}</div>
                              <div className="text-sm text-gray-500">{tax.code}</div>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {tax.tax_type === 'PERCENTAGE' ? t('settings:tax.configurations.form.typePercentage') : t('settings:tax.configurations.form.typeFixed')}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {tax.tax_type === 'PERCENTAGE' ? `${tax.percentage_rate}%` : tax.fixed_amount}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {tax.applies_to === 'LINE_ITEMS' ? t('settings:tax.configurations.form.appliesToLineItems') : t('settings:tax.configurations.form.appliesToDocument')}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                            tax.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                          }`}>
                            {tax.is_active ? t('common:yes') : t('common:no')}
                          </span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                          <button
                            type="button"
                            onClick={() => { handleEditTax(tax); }}
                            className="text-blue-600 hover:text-blue-900 me-4"
                          >
                            <Pencil className="h-4 w-4" />
                          </button>
                          <button
                            type="button"
                            onClick={() => { setDeletingTaxId(tax.id); }}
                            className="text-red-600 hover:text-red-900"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <TaxConfigFormModal
              key={editingTax?.id ?? 'new'}
              isOpen={isModalOpen}
              onClose={() => { setIsModalOpen(false); setEditingTax(null); }}
              onSaved={() => { setIsModalOpen(false); setEditingTax(null); }}
              editingTax={editingTax}
            />
          </div>
        </TabsContent>
      </Tabs>

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={deletingTaxId !== null}
        onClose={() => { setDeletingTaxId(null); }}
        onConfirm={() => {
          void handleDeleteTax()
        }}
        title={t('settings:tax.configurations.confirmDelete.title')}
        message={t('settings:tax.configurations.confirmDelete.message')}
        confirmText={t('common:delete')}
        variant="danger"
        isLoading={deleteTax.isPending}
      />
    </div>
  )
}
