import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Save, Info, Clock, AlertTriangle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { Button } from '../../../components/atoms/Button/Button'
import { useCompany } from '../../../hooks/useCompany'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

interface Company {
  id: string
  name: string
  default_target_margin?: string
  default_minimum_margin?: string
  allow_below_cost_sales?: boolean
}

interface CompanyResponse {
  data: Company
}

interface ReservationSettings {
  sales_order_expiry_days: number
  ecommerce_cart_expiry_minutes: number
  marketplace_order_expiry_hours: number
  customer_return_expiry_days: number
  high_value_alert_threshold: string
  inventory_count_trigger_threshold: string
  auto_reserve_on_sales_order: boolean
}

interface ReservationSettingsResponse {
  data: ReservationSettings
}

export function InventorySettings() {
  const { t } = useTranslation(['common', 'inventory'])
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  // Fetch current company settings
  const { data: companyData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['company-settings']),
    queryFn: async () => {
      const response = await api.get<CompanyResponse>('/company')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  // Fetch reservation settings
  const { data: reservationData, isLoading: isLoadingReservation } = useQuery({
    queryKey: tenantScopedKey(['reservation-settings', currentCompany?.id]),
    queryFn: async () => {
      if (!currentCompany?.id) return null
      const response = await api.get<ReservationSettingsResponse>(
        `/companies/${currentCompany.id}/reservation-settings`
      )
      return response.data.data
    },
    enabled: !!currentCompany?.id && tenantId !== null && companyId !== null,
  })

  const company = companyData?.data

  const [settings, setSettings] = useState({
    default_target_margin: company?.default_target_margin || '30.00',
    default_minimum_margin: company?.default_minimum_margin || '15.00',
    allow_below_cost_sales: company?.allow_below_cost_sales || false,
  })

  const [reservationSettings, setReservationSettings] = useState({
    sales_order_expiry_days: 30,
    ecommerce_cart_expiry_minutes: 30,
    marketplace_order_expiry_hours: 24,
    customer_return_expiry_days: 14,
    high_value_alert_threshold: '10000.00',
    inventory_count_trigger_threshold: '5000.00',
    auto_reserve_on_sales_order: true,
  })

  // Update settings when company data loads
  useEffect(() => {
    if (company) {
      setSettings({
        default_target_margin: company.default_target_margin || '30.00',
        default_minimum_margin: company.default_minimum_margin || '15.00',
        allow_below_cost_sales: company.allow_below_cost_sales || false,
      })
    }
  }, [company])

  // Update reservation settings when data loads
  useEffect(() => {
    if (reservationData) {
      setReservationSettings(reservationData)
    }
  }, [reservationData])

  // Save inventory settings mutation
  const saveMutation = useMutation({
    mutationFn: async () => {
      await api.patch('/company', settings)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['company-settings']) })
    },
    onError: () => {
      toast.error(t('inventory:settings.messages.inventorySaveFailed'))
    },
  })

  // Save reservation settings mutation
  const saveReservationMutation = useMutation({
    mutationFn: async () => {
      if (!currentCompany?.id) throw new Error('No company selected')
      await api.put(`/companies/${currentCompany.id}/reservation-settings`, reservationSettings)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['reservation-settings', currentCompany?.id]) })
    },
    onError: () => {
      toast.error(t('inventory:settings.messages.reservationSaveFailed'))
    },
  })

  const handleSave = async () => {
    const promises = []
    if (hasInventoryChanges) {
      promises.push(saveMutation.mutateAsync())
    }
    if (hasReservationChanges) {
      promises.push(saveReservationMutation.mutateAsync())
    }

    try {
      await Promise.all(promises)
      toast.success(t('inventory:settings.messages.saved'))
    } catch (error) {
      // Errors are already handled in individual mutations
    }
  }

  const hasInventoryChanges = company && (
    settings.default_target_margin !== (company.default_target_margin || '30.00') ||
    settings.default_minimum_margin !== (company.default_minimum_margin || '15.00') ||
    settings.allow_below_cost_sales !== (company.allow_below_cost_sales || false)
  )

  const hasReservationChanges = reservationData && (
    reservationSettings.sales_order_expiry_days !== reservationData.sales_order_expiry_days ||
    reservationSettings.ecommerce_cart_expiry_minutes !== reservationData.ecommerce_cart_expiry_minutes ||
    reservationSettings.marketplace_order_expiry_hours !== reservationData.marketplace_order_expiry_hours ||
    reservationSettings.customer_return_expiry_days !== reservationData.customer_return_expiry_days ||
    reservationSettings.high_value_alert_threshold !== reservationData.high_value_alert_threshold ||
    reservationSettings.inventory_count_trigger_threshold !== reservationData.inventory_count_trigger_threshold ||
    reservationSettings.auto_reserve_on_sales_order !== reservationData.auto_reserve_on_sales_order
  )

  const hasChanges = hasInventoryChanges || hasReservationChanges

  if (isLoading || isLoadingReservation) {
    return (
      <div className="flex items-center justify-center p-8">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-gray-300 border-t-blue-600" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">{t('inventory:settings.title')}</h2>
        <p className="mt-1 text-sm text-gray-600">
          {t('inventory:settings.description')}
        </p>
      </div>

      {/* Margin Policies */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h3 className="text-sm font-medium text-gray-900">{t('inventory:settings.margins.title')}</h3>
        <p className="mt-1 text-xs text-gray-600">
          {t('inventory:settings.margins.description')}
        </p>

        <div className="mt-4 grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('inventory:settings.margins.targetLabel')}
            </label>
            <input
              type="number"
              step="0.01"
              min="0"
              max="100"
              value={settings.default_target_margin}
              onChange={(e) => { setSettings({ ...settings, default_target_margin: e.target.value }); }}
              className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              {t('inventory:settings.margins.targetHint')}
            </p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('inventory:settings.margins.minimumLabel')}
            </label>
            <input
              type="number"
              step="0.01"
              min="0"
              max="100"
              value={settings.default_minimum_margin}
              onChange={(e) => { setSettings({ ...settings, default_minimum_margin: e.target.value }); }}
              className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              {t('inventory:settings.margins.minimumHint')}
            </p>
          </div>
        </div>

        <div className="mt-4 rounded-lg bg-blue-50 p-3">
          <div className="flex items-start gap-2">
            <Info className="h-4 w-4 shrink-0 text-blue-600" />
            <div className="text-xs text-blue-800">
              <strong>{t('inventory:settings.margins.calculationInfo')}</strong> {t('inventory:settings.margins.calculationFormula')}
              <br />
              {t('inventory:settings.margins.calculationExample')}
            </div>
          </div>
        </div>
      </div>

      {/* Sales Restrictions */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h3 className="text-sm font-medium text-gray-900">{t('inventory:settings.restrictions.title')}</h3>
        <p className="mt-1 text-xs text-gray-600">
          {t('inventory:settings.restrictions.description')}
        </p>

        <div className="mt-4">
          <label className="flex items-start gap-3">
            <input
              type="checkbox"
              checked={settings.allow_below_cost_sales}
              onChange={(e) => { setSettings({ ...settings, allow_below_cost_sales: e.target.checked }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-900">
                {t('inventory:settings.restrictions.allowBelowCost')}
              </div>
              <p className="text-xs text-gray-600">
                {t('inventory:settings.restrictions.allowBelowCostHint')}
              </p>
            </div>
          </label>
        </div>
      </div>

      {/* Stock Reservation Settings */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <div className="flex items-center gap-2">
          <Clock className="h-5 w-5 text-blue-600" />
          <h3 className="text-sm font-medium text-gray-900">{t('inventory:settings.reservations.title')}</h3>
        </div>
        <p className="mt-1 text-xs text-gray-600">
          {t('inventory:settings.reservations.description')}
        </p>

        {/* Expiry Timeouts */}
        <div className="mt-4">
          <h4 className="text-xs font-medium text-gray-700 uppercase tracking-wide">{t('inventory:settings.reservations.expiry.heading')}</h4>
          <div className="mt-3 grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('inventory:settings.reservations.expiry.salesOrder.label')}
              </label>
              <input
                type="number"
                min="1"
                max="365"
                value={reservationSettings.sales_order_expiry_days}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, sales_order_expiry_days: parseInt(e.target.value) || 30 }); }}
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                {t('inventory:settings.reservations.expiry.salesOrder.hint')}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('inventory:settings.reservations.expiry.cart.label')}
              </label>
              <input
                type="number"
                min="5"
                max="1440"
                value={reservationSettings.ecommerce_cart_expiry_minutes}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, ecommerce_cart_expiry_minutes: parseInt(e.target.value) || 30 }); }}
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                {t('inventory:settings.reservations.expiry.cart.hint')}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('inventory:settings.reservations.expiry.marketplace.label')}
              </label>
              <input
                type="number"
                min="1"
                max="168"
                value={reservationSettings.marketplace_order_expiry_hours}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, marketplace_order_expiry_hours: parseInt(e.target.value) || 24 }); }}
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                {t('inventory:settings.reservations.expiry.marketplace.hint')}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('inventory:settings.reservations.expiry.returns.label')}
              </label>
              <input
                type="number"
                min="1"
                max="90"
                value={reservationSettings.customer_return_expiry_days}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, customer_return_expiry_days: parseInt(e.target.value) || 14 }); }}
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                {t('inventory:settings.reservations.expiry.returns.hint')}
              </p>
            </div>
          </div>
        </div>

        {/* Fraud Detection Thresholds */}
        <div className="mt-6">
          <div className="flex items-center gap-2">
            <AlertTriangle className="h-4 w-4 text-amber-600" />
            <h4 className="text-xs font-medium text-gray-700 uppercase tracking-wide">{t('inventory:settings.reservations.fraud.heading')}</h4>
          </div>
          <div className="mt-3 grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('inventory:settings.reservations.fraud.highValue.label')} ({currentCompany?.currency || 'USD'})
              </label>
              <input
                type="number"
                step="0.01"
                min="0"
                value={reservationSettings.high_value_alert_threshold}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, high_value_alert_threshold: e.target.value }); }}
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                {t('inventory:settings.reservations.fraud.highValue.hint')}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700">
                {t('inventory:settings.reservations.fraud.countTrigger.label')} ({currentCompany?.currency || 'USD'})
              </label>
              <input
                type="number"
                step="0.01"
                min="0"
                value={reservationSettings.inventory_count_trigger_threshold}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, inventory_count_trigger_threshold: e.target.value }); }}
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                {t('inventory:settings.reservations.fraud.countTrigger.hint')}
              </p>
            </div>
          </div>
        </div>

        {/* Auto-reservation Setting */}
        <div className="mt-6">
          <label className="flex items-start gap-3">
            <input
              type="checkbox"
              checked={reservationSettings.auto_reserve_on_sales_order}
              onChange={(e) => { setReservationSettings({ ...reservationSettings, auto_reserve_on_sales_order: e.target.checked }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className="text-sm font-medium text-gray-900">
                {t('inventory:settings.reservations.autoReserve.label')}
              </div>
              <p className="text-xs text-gray-600">
                {t('inventory:settings.reservations.autoReserve.hint')}
              </p>
            </div>
          </label>
        </div>

        {/* Info Box */}
        <div className="mt-4 rounded-lg bg-blue-50 p-3">
          <div className="flex items-start gap-2">
            <Info className="h-4 w-4 shrink-0 text-blue-600" />
            <div className="text-xs text-blue-800">
              <strong>{t('inventory:settings.reservations.info.title')}</strong> {t('inventory:settings.reservations.info.description')}
            </div>
          </div>
        </div>
      </div>

      {/* Save Button */}
      <div className="flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
        {hasChanges && (
          <span className="text-sm text-gray-600">{t('inventory:settings.messages.unsavedChanges')}</span>
        )}
        <Button
          onClick={handleSave}
          disabled={!hasChanges || saveMutation.isPending || saveReservationMutation.isPending}
          size="md"
        >
          <Save className="mr-2 h-4 w-4" />
          {(saveMutation.isPending || saveReservationMutation.isPending) ? t('common:saving') : `${t('common:save')} ${t('common:settings.title')}`}
        </Button>
      </div>
    </div>
  )
}
