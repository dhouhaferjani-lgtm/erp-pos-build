import { useState } from 'react'
import { Link } from 'react-router-dom'
import { EmptyState } from '../../components/molecules/EmptyState/EmptyState'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Save, Plus, Pencil, Trash2, GripVertical } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPut, getErrorMessage } from '../../lib/api'
import { formatPercent } from '../../lib/format'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useCompany } from '../../hooks/useCompany'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { usePermissions } from '../../hooks/usePermissions'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors, colors, semanticColorTokens as colorTokens } from '../../lib/designTokens'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/molecules/Tabs'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Button } from '../../components/atoms/Button'
import { FormField } from '../../components/atoms/FormField'
import { Input } from '../../components/atoms/Input'
import { Select } from '../../components/atoms/Select'
import { StatusBadge } from '../../components/atoms/StatusBadge/StatusBadge'
import { PageHeader } from '../../components/molecules/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import {
  useTaxConfigurations,
  useDeleteTaxConfiguration,
} from './hooks/useTaxConfigurations'
import { TaxConfigFormModal } from '../../components/organisms/TaxConfigFormModal'
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
  const { hasPermission } = usePermissions()
  // ORCHESTRATOR RULING (F1, 2026-08-02): company-wide config writes are
  // admin-only via settings.update. settings.view holders (e.g. manager,
  // viewer) may still read this tab — only the mutation affordance is
  // disabled, not the page.
  const canEdit = hasPermission('settings.update')
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
          <div className={cn('animate-spin rounded-full h-12 w-12 border-b-2 mx-auto', borderColors.primary)}></div>
          <p className={cn('mt-4', textColors.tertiary)}>{t('common:loading')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <PageHeader
        title={t('settings:tax.configurations.pageTitle')}
        subtitle={t('settings:tax.configurations.description')}
        breadcrumb={
          <Link
            to="/settings"
            className={cn('inline-flex items-center text-sm', textColors.tertiary, textColors.hoverPrimary)}
          >
            <ArrowLeft className="h-4 w-4 me-1" />
            {t('common:back')}
          </Link>
        }
      />

      {/* Tabs */}
      <Tabs defaultValue="profile" value={activeTab} onChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="profile">{t('settings:tax.configurations.tabs.profile')}</TabsTrigger>
          <TabsTrigger value="taxes">{t('settings:tax.configurations.tabs.taxes')}</TabsTrigger>
        </TabsList>

        {/* Company Tax Profile Tab */}
        <TabsContent value="profile" className="mt-6">
          <form onSubmit={handleSubmit}>
            <div className={cn(tokens.card.base, 'p-0')}>
              {/* Tax Status */}
              <div className={cn('px-6 py-6 border-b', borderColors.light)}>
                <h2 className={cn(tokens.heading.section, 'mb-4')}>
                  {t('settings:tax.configurations.profile.title')}
                </h2>
                <p className={cn('text-sm mb-4', textColors.tertiary)}>
                  {t('settings:tax.configurations.profile.description')}
                </p>

                <div className="space-y-4 max-w-md">
                  <div>
                    <label className={cn(tokens.label.base, 'mb-2')}>
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
                          className={`mt-0.5 h-4 w-4 ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
                        />
                        <span className="ms-3">
                          <span className={cn('block text-sm font-medium', textColors.primary)}>
                            {t('settings:tax.configurations.profile.registered')}
                          </span>
                          <span className={cn('block text-sm', textColors.tertiary)}>
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
                          className={`mt-0.5 h-4 w-4 ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
                        />
                        <span className="ms-3">
                          <span className={cn('block text-sm font-medium', textColors.primary)}>
                            {t('settings:tax.configurations.profile.nonRegistered')}
                          </span>
                          <span className={cn('block text-sm', textColors.tertiary)}>
                            {t('settings:tax.configurations.profile.nonRegisteredDescription')}
                          </span>
                        </span>
                      </label>
                    </div>
                  </div>

                  {formData.tax_status === 'REGISTERED' && (
                    <FormField
                      label={t('settings:tax.configurations.profile.vatNumber')}
                      htmlFor="vat_number"
                    >
                      <Input
                        type="text"
                        id="vat_number"
                        value={formData.vat_registration_number ?? ''}
                        onChange={(e) => { handleChange('vat_registration_number', e.target.value || null); }}
                        placeholder={t('settings:tax.configurations.profile.vatNumberPlaceholder')}
                      />
                    </FormField>
                  )}
                </div>
              </div>

              {/* Default Tax Rate */}
              <div className={cn('px-6 py-6 border-b', borderColors.light)}>
                <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('settings:tax.defaultTaxRate.title')}</h2>
                <p className={cn('text-sm mb-4', textColors.tertiary)}>{t('settings:tax.defaultTaxRate.description')}</p>

                <div className="max-w-xs">
                  <FormField
                    label={t('settings:tax.defaultTaxRate.label')}
                    htmlFor="default_tax_rate"
                    helperText={t('settings:tax.defaultTaxRate.help')}
                  >
                    <div className="relative">
                      <Input
                        type="number"
                        id="default_tax_rate"
                        step="0.01"
                        min="0"
                        max="100"
                        value={formData.default_tax_rate ?? ''}
                        onChange={(e) => { handleChange('default_tax_rate', e.target.value || null); }}
                        className="pe-8"
                        placeholder="19.00"
                      />
                      <div className="absolute inset-y-0 end-0 pe-3 flex items-center pointer-events-none">
                        <span className={cn('text-sm', textColors.tertiary)}>%</span>
                      </div>
                    </div>
                  </FormField>
                </div>
              </div>

              {/* Fiscal Year */}
              <div className="px-6 py-6">
                <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('settings:tax.fiscalYear.title')}</h2>
                <p className={cn('text-sm mb-4', textColors.tertiary)}>{t('settings:tax.fiscalYear.description')}</p>

                <div className="max-w-xs">
                  <FormField
                    label={t('settings:tax.fiscalYear.label')}
                    htmlFor="fiscal_year_start_month"
                    helperText={t('settings:tax.fiscalYear.help')}
                  >
                    <Select
                      id="fiscal_year_start_month"
                      value={formData.fiscal_year_start_month}
                      onChange={(e) => { handleChange('fiscal_year_start_month', parseInt(e.target.value)); }}
                    >
                      {MONTH_VALUES.map(value => (
                        <option key={value} value={value}>
                          {t(`settings:tax.months.${String(value)}`)}
                        </option>
                      ))}
                    </Select>
                  </FormField>
                </div>
              </div>
            </div>

            {/* Actions */}
            <div className="mt-6 flex items-center justify-end gap-3">
              <Link to="/settings">
                <Button type="button" variant="secondary">
                  {t('common:cancel')}
                </Button>
              </Link>
              <Button
                type="submit"
                disabled={!isDirty || updateMutation.isPending || !canEdit}
                title={canEdit ? undefined : t('common:permissions.readOnlyEditHint')}
                className="gap-2"
              >
                <Save className="h-4 w-4" />
                {updateMutation.isPending ? t('common:saving') : t('common:save')}
              </Button>
            </div>
          </form>
        </TabsContent>

        {/* Tax Configurations Tab */}
        <TabsContent value="taxes" className="mt-6">
          <div className="space-y-6">
            {/* Add Tax Button */}
            <div className="flex justify-end">
              <Button
                type="button"
                onClick={handleCreateTax}
                className="gap-2"
              >
                <Plus className="h-4 w-4" />
                {t('settings:tax.configurations.addButton')}
              </Button>
            </div>

            {/* Tax List */}
            {taxConfigurations.length === 0 ? (
              <div className={cn('rounded-lg border', colors.white, borderColors.light)}>
                <EmptyState title={t('settings:tax.configurations.table.noData')} />
              </div>
            ) : (
              <div className={cn(tokens.card.base, 'p-0 overflow-hidden')}>
                <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
                  <thead className={tokens.table.header}>
                    <tr>
                      <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                        {t('settings:tax.configurations.table.name')}
                      </th>
                      <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                        {t('settings:tax.configurations.table.type')}
                      </th>
                      <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                        {t('settings:tax.configurations.table.rate')}
                      </th>
                      <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                        {t('settings:tax.configurations.table.appliesTo')}
                      </th>
                      <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                        {t('settings:tax.configurations.table.active')}
                      </th>
                      <th className={cn('px-6 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                        {t('settings:tax.configurations.table.actions')}
                      </th>
                    </tr>
                  </thead>
                  <tbody className={cn(colors.white, 'divide-y', borderColors.divideDefault)}>
                    {taxConfigurations.map((tax) => (
                      <tr key={tax.id}>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center">
                            <GripVertical className={cn('h-4 w-4 me-2', textColors.disabled)} />
                            <div>
                              <div className={cn('text-sm font-medium', textColors.primary)}>{tax.name}</div>
                              <div className={cn('text-sm', textColors.tertiary)}>{tax.code}</div>
                            </div>
                          </div>
                        </td>
                        <td className={cn('px-6 py-4 whitespace-nowrap text-sm', textColors.tertiary)}>
                          {tax.tax_type === 'PERCENTAGE' ? t('settings:tax.configurations.form.typePercentage') : t('settings:tax.configurations.form.typeFixed')}
                        </td>
                        <td className={cn('px-6 py-4 whitespace-nowrap text-sm', textColors.primary)}>
                          {tax.tax_type === 'PERCENTAGE' ? formatPercent(tax.percentage_rate) : tax.fixed_amount}
                        </td>
                        <td className={cn('px-6 py-4 whitespace-nowrap text-sm', textColors.tertiary)}>
                          {tax.applies_to === 'LINE_ITEMS' ? t('settings:tax.configurations.form.appliesToLineItems') : t('settings:tax.configurations.form.appliesToDocument')}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <StatusBadge tone={tax.is_active ? 'success' : 'neutral'}>
                            {tax.is_active ? t('common:yes') : t('common:no')}
                          </StatusBadge>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => { handleEditTax(tax); }}
                            aria-label={t('common:edit')}
                            className={cn('me-2', textColors.brand)}
                          >
                            <Pencil className="h-4 w-4" />
                          </Button>
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => { setDeletingTaxId(tax.id); }}
                            aria-label={t('common:delete')}
                            className={textColors.error}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </DataTable>
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
