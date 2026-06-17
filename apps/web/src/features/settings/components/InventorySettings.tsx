import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Save, Info, Clock, AlertTriangle, Loader2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { cn } from '../../../lib/utils'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { Button } from '../../../components/atoms/Button/Button'
import { Checkbox } from '../../../components/atoms'
import { FormField } from '../../../components/atoms/FormField'
import { Input } from '../../../components/atoms/Input'
import { MoneyInput } from '../../../components/atoms/MoneyInput'
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
    default_target_margin: company?.default_target_margin ?? '30.00',
    default_minimum_margin: company?.default_minimum_margin ?? '15.00',
    allow_below_cost_sales: company?.allow_below_cost_sales ?? false,
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
        default_target_margin: company.default_target_margin ?? '30.00',
        default_minimum_margin: company.default_minimum_margin ?? '15.00',
        allow_below_cost_sales: company.allow_below_cost_sales ?? false,
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

  const hasInventoryChanges = company && (
    settings.default_target_margin !== (company.default_target_margin ?? '30.00') ||
    settings.default_minimum_margin !== (company.default_minimum_margin ?? '15.00') ||
    settings.allow_below_cost_sales !== (company.allow_below_cost_sales ?? false)
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
    } catch {
      // Errors are already handled in individual mutations
    }
  }

  if (isLoading || isLoadingReservation) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className={cn('h-8 w-8 animate-spin', textColors.brand)} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className={cn(tokens.heading.section)}>{t('inventory:settings.title')}</h2>
        <p className={cn('mt-1 text-sm', textColors.tertiary)}>
          {t('inventory:settings.description')}
        </p>
      </div>

      {/* Margin Policies */}
      <div className={tokens.card.base}>
        <h3 className={cn(tokens.heading.section)}>{t('inventory:settings.margins.title')}</h3>
        <p className={cn('mt-1 text-xs', textColors.tertiary)}>
          {t('inventory:settings.margins.description')}
        </p>

        <div className="mt-4 grid grid-cols-2 gap-4">
          <FormField
            label={t('inventory:settings.margins.targetLabel')}
            htmlFor="default_target_margin"
            helperText={t('inventory:settings.margins.targetHint')}
          >
            <Input
              id="default_target_margin"
              type="number"
              min="0"
              max="100"
              value={settings.default_target_margin}
              onChange={(e) => { setSettings({ ...settings, default_target_margin: e.target.value }); }}
            />
          </FormField>

          <FormField
            label={t('inventory:settings.margins.minimumLabel')}
            htmlFor="default_minimum_margin"
            helperText={t('inventory:settings.margins.minimumHint')}
          >
            <Input
              id="default_minimum_margin"
              type="number"
              min="0"
              max="100"
              value={settings.default_minimum_margin}
              onChange={(e) => { setSettings({ ...settings, default_minimum_margin: e.target.value }); }}
            />
          </FormField>
        </div>

        <div className={cn('mt-4 rounded-lg p-3', tokens.alert.info)}>
          <div className="flex items-start gap-2">
            <Info className={cn('h-4 w-4 shrink-0', textColors.brand)} />
            <div className="text-xs">
              <strong>{t('inventory:settings.margins.calculationInfo')}</strong> {t('inventory:settings.margins.calculationFormula')}
              <br />
              {t('inventory:settings.margins.calculationExample')}
            </div>
          </div>
        </div>
      </div>

      {/* Sales Restrictions */}
      <div className={tokens.card.base}>
        <h3 className={cn(tokens.heading.section)}>{t('inventory:settings.restrictions.title')}</h3>
        <p className={cn('mt-1 text-xs', textColors.tertiary)}>
          {t('inventory:settings.restrictions.description')}
        </p>

        <div className="mt-4">
          <label className="flex items-start gap-3">
            <Checkbox
              checked={settings.allow_below_cost_sales}
              onChange={(e) => { setSettings({ ...settings, allow_below_cost_sales: e.target.checked }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className={cn('text-sm font-medium', textColors.primary)}>
                {t('inventory:settings.restrictions.allowBelowCost')}
              </div>
              <p className={cn('text-xs', textColors.tertiary)}>
                {t('inventory:settings.restrictions.allowBelowCostHint')}
              </p>
            </div>
          </label>
        </div>
      </div>

      {/* Stock Reservation Settings */}
      <div className={tokens.card.base}>
        <div className="flex items-center gap-2">
          <Clock className={cn('h-5 w-5', textColors.brand)} />
          <h3 className={cn(tokens.heading.section)}>{t('inventory:settings.reservations.title')}</h3>
        </div>
        <p className={cn('mt-1 text-xs', textColors.tertiary)}>
          {t('inventory:settings.reservations.description')}
        </p>

        {/* Expiry Timeouts */}
        <div className="mt-4">
          <h4 className={cn('text-xs font-medium uppercase tracking-wide', textColors.secondary)}>{t('inventory:settings.reservations.expiry.heading')}</h4>
          <div className="mt-3 grid grid-cols-2 gap-4">
            <FormField
              label={t('inventory:settings.reservations.expiry.salesOrder.label')}
              htmlFor="sales_order_expiry_days"
              helperText={t('inventory:settings.reservations.expiry.salesOrder.hint')}
            >
              <Input
                id="sales_order_expiry_days"
                type="number"
                min="1"
                max="365"
                value={reservationSettings.sales_order_expiry_days}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, sales_order_expiry_days: parseInt(e.target.value) || 30 }); }}
              />
            </FormField>

            <FormField
              label={t('inventory:settings.reservations.expiry.cart.label')}
              htmlFor="ecommerce_cart_expiry_minutes"
              helperText={t('inventory:settings.reservations.expiry.cart.hint')}
            >
              <Input
                id="ecommerce_cart_expiry_minutes"
                type="number"
                min="5"
                max="1440"
                value={reservationSettings.ecommerce_cart_expiry_minutes}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, ecommerce_cart_expiry_minutes: parseInt(e.target.value) || 30 }); }}
              />
            </FormField>

            <FormField
              label={t('inventory:settings.reservations.expiry.marketplace.label')}
              htmlFor="marketplace_order_expiry_hours"
              helperText={t('inventory:settings.reservations.expiry.marketplace.hint')}
            >
              <Input
                id="marketplace_order_expiry_hours"
                type="number"
                min="1"
                max="168"
                value={reservationSettings.marketplace_order_expiry_hours}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, marketplace_order_expiry_hours: parseInt(e.target.value) || 24 }); }}
              />
            </FormField>

            <FormField
              label={t('inventory:settings.reservations.expiry.returns.label')}
              htmlFor="customer_return_expiry_days"
              helperText={t('inventory:settings.reservations.expiry.returns.hint')}
            >
              <Input
                id="customer_return_expiry_days"
                type="number"
                min="1"
                max="90"
                value={reservationSettings.customer_return_expiry_days}
                onChange={(e) => { setReservationSettings({ ...reservationSettings, customer_return_expiry_days: parseInt(e.target.value) || 14 }); }}
              />
            </FormField>
          </div>
        </div>

        {/* Fraud Detection Thresholds */}
        <div className="mt-6">
          <div className="flex items-center gap-2">
            <AlertTriangle className={cn('h-4 w-4', textColors.warningDark)} />
            <h4 className={cn('text-xs font-medium uppercase tracking-wide', textColors.secondary)}>{t('inventory:settings.reservations.fraud.heading')}</h4>
          </div>
          <div className="mt-3 grid grid-cols-2 gap-4">
            <FormField
              label={`${t('inventory:settings.reservations.fraud.highValue.label')} (${currentCompany?.currency ?? 'USD'})`}
              htmlFor="high_value_alert_threshold"
              helperText={t('inventory:settings.reservations.fraud.highValue.hint')}
            >
              <MoneyInput
                id="high_value_alert_threshold"
                value={reservationSettings.high_value_alert_threshold}
                onChange={(v) => { setReservationSettings({ ...reservationSettings, high_value_alert_threshold: v }); }}
                currency={currentCompany?.currency ?? 'USD'}
                min="0"
              />
            </FormField>

            <FormField
              label={`${t('inventory:settings.reservations.fraud.countTrigger.label')} (${currentCompany?.currency ?? 'USD'})`}
              htmlFor="inventory_count_trigger_threshold"
              helperText={t('inventory:settings.reservations.fraud.countTrigger.hint')}
            >
              <MoneyInput
                id="inventory_count_trigger_threshold"
                value={reservationSettings.inventory_count_trigger_threshold}
                onChange={(v) => { setReservationSettings({ ...reservationSettings, inventory_count_trigger_threshold: v }); }}
                currency={currentCompany?.currency ?? 'USD'}
                min="0"
              />
            </FormField>
          </div>
        </div>

        {/* Auto-reservation Setting */}
        <div className="mt-6">
          <label className="flex items-start gap-3">
            <Checkbox
              checked={reservationSettings.auto_reserve_on_sales_order}
              onChange={(e) => { setReservationSettings({ ...reservationSettings, auto_reserve_on_sales_order: e.target.checked }); }}
              className="mt-0.5"
            />
            <div className="flex-1">
              <div className={cn('text-sm font-medium', textColors.primary)}>
                {t('inventory:settings.reservations.autoReserve.label')}
              </div>
              <p className={cn('text-xs', textColors.tertiary)}>
                {t('inventory:settings.reservations.autoReserve.hint')}
              </p>
            </div>
          </label>
        </div>

        {/* Info Box */}
        <div className={cn('mt-4 rounded-lg p-3', tokens.alert.info)}>
          <div className="flex items-start gap-2">
            <Info className={cn('h-4 w-4 shrink-0', textColors.brand)} />
            <div className="text-xs">
              <strong>{t('inventory:settings.reservations.info.title')}</strong> {t('inventory:settings.reservations.info.description')}
            </div>
          </div>
        </div>
      </div>

      {/* Save Button */}
      <div className={cn('flex items-center justify-end gap-3 border-t pt-4', borderColors.light)}>
        {hasChanges && (
          <span className={cn('text-sm', textColors.tertiary)}>{t('inventory:settings.messages.unsavedChanges')}</span>
        )}
        <Button
          onClick={() => { void handleSave() }}
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
