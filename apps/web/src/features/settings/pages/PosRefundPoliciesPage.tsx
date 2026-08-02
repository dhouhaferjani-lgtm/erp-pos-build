import { useEffect } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useForm, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, Loader2, ShieldX, RotateCcw, Save } from 'lucide-react'
import { toast } from 'sonner'
import { tokens, textColors, borderColors, colors, focusRing , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { Checkbox , Button, Input, Select } from '@/components/atoms'
import { usePermissions } from '@/hooks/usePermissions'
import { usePosRefundPolicies } from '../hooks/usePosRefundPolicies'
import { useUpdatePosRefundPolicies } from '../hooks/useUpdatePosRefundPolicies'
import { RefundPoliciesSection } from '../components/RefundPoliciesSection'
import {
  posRefundPoliciesSchema,
  POS_REFUND_POLICY_DEFAULTS,
} from '../types/posRefundPolicies'
import type { PosRefundPoliciesForm, RefundDestination } from '../types/posRefundPolicies'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

// ─── Sub-components ───────────────────────────────────────────────────────────

function FieldRow({
  label,
  help,
  error,
  children,
}: {
  label: string
  help: string
  error?: string | undefined
  children: React.ReactNode
}) {
  return (
    <div>
      <label className={tokens.label.base}>{label}</label>
      {children}
      {error ? (
        <p className={tokens.helperText.error}>{error}</p>
      ) : (
        <p className={tokens.helperText.base}>{help}</p>
      )}
    </div>
  )
}

function Toggle({
  checked,
  onChange,
  'data-testid': testId,
}: {
  checked: boolean
  onChange: (v: boolean) => void
  'data-testid'?: string
}) {
  return (
    <Button
      type="button"
      role="switch"
      aria-checked={checked}
      data-testid={testId}
      onClick={() => { onChange(!checked) }}
      className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 ${focusRing.primary} ${
        checked ? colors.primary[600] : colors.neutral[200]
      }`}
    >
      <span
        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full ${colors.white} shadow ring-0 transition-transform ${
          checked ? 'translate-x-5' : 'translate-x-0'
        }`}
      />
    </Button>
  )
}

// ─── Access Denied ────────────────────────────────────────────────────────────

function AccessDenied() {
  const { t } = useTranslation(['refund-policies'])
  return (
    <div
      data-testid="access-denied"
      className="flex min-h-[400px] flex-col items-center justify-center p-8 text-center"
    >
      <div className={`rounded-full ${colors.error[100]} p-4 mb-4`}>
        <ShieldX className={`h-12 w-12 ${textColors.error}`} />
      </div>
      <h2 className={`text-xl font-semibold ${textColors.primary} mb-2`}>
        {t('refund-policies:accessDenied.title')}
      </h2>
      <p className={`${textColors.tertiary} max-w-md`}>
        {t('refund-policies:accessDenied.description')}
      </p>
    </div>
  )
}

// ─── Main page ────────────────────────────────────────────────────────────────

export function PosRefundPoliciesPage() {
  const { t } = useTranslation(['refund-policies', 'common'])
  const { canAccessModule, hasPermission } = usePermissions()
  // ORCHESTRATOR RULING (F1, 2026-08-02): company-wide config writes are
  // admin-only via settings.update. settings.view holders (e.g. manager,
  // viewer) may still read this page — only the mutation affordance is
  // disabled, not the page.
  const canEdit = hasPermission('settings.update')
  const { data: settings, isLoading } = usePosRefundPolicies()
  const updateMutation = useUpdatePosRefundPolicies()

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors, isDirty },
  } = useForm<PosRefundPoliciesForm>({
    resolver: zodResolver(posRefundPoliciesSchema),
    defaultValues: POS_REFUND_POLICY_DEFAULTS,
  })

  // Populate form once settings arrive
  useEffect(() => {
    if (settings) {
      reset(settings)
    }
  }, [settings, reset])

  // Permission gate
  if (!canAccessModule('settings')) {
    return <AccessDenied />
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12" data-testid="loading-spinner">
        <Loader2 className={`h-8 w-8 animate-spin ${textColors.brand}`} />
      </div>
    )
  }

  const handleReset = () => {
    reset(POS_REFUND_POLICY_DEFAULTS)
  }

  const onSubmit = (data: PosRefundPoliciesForm) => {
    updateMutation.mutate(data, {
      onSuccess: () => {
        toast.success(t('refund-policies:messages.saved'))
      },
      onError: (err: unknown) => {
        const message = err instanceof Error ? err.message : String(err)
        toast.error(t('refund-policies:messages.error', { message }))
      },
    })
  }

  const toggleDestination = (
    dest: RefundDestination,
    currentDests: RefundDestination[],
    onChange: (v: RefundDestination[]) => void
  ) => {
    if (currentDests.includes(dest)) {
      onChange(currentDests.filter((d) => d !== dest))
    } else {
      onChange([...currentDests, dest])
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/settings"
          className={`inline-flex items-center gap-2 text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${textColors.primary}`}>
            {t('refund-policies:pageTitle')}
          </PageHeaderTitle>
          <p className={`text-sm ${textColors.tertiary} mt-1`}>
            {t('refund-policies:pageSubtitle')}
          </p>
        </div>
      </div>

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} noValidate className="space-y-4">

        {/* ── Section 1: Return window ─────────────────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.returnWindow')}>
          <FieldRow
            label={t('refund-policies:fields.customer_return_expiry_days.label')}
            help={t('refund-policies:fields.customer_return_expiry_days.help')}
            error={errors.customer_return_expiry_days?.message}
          >
            <Input
              type="number"
              min={0}
              max={90}
              data-testid="field-customer_return_expiry_days"
              {...register('customer_return_expiry_days', { valueAsNumber: true })}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.customer_history_window_days.label')}
            help={t('refund-policies:fields.customer_history_window_days.help')}
            error={errors.customer_history_window_days?.message}
          >
            <Input
              type="number"
              min={0}
              max={365}
              data-testid="field-customer_history_window_days"
              {...register('customer_history_window_days', { valueAsNumber: true })}
            />
          </FieldRow>

          <div>
            <label className={tokens.label.base}>
              {t('refund-policies:fields.out_of_window_policy.label')}
            </label>
            <div className="mt-2 space-y-2">
              {(['refuse', 'voucher_only'] as const).map((opt) => (
                <label key={opt} className="flex items-center gap-3 cursor-pointer">
                  <input
                    type="radio"
                    value={opt}
                    {...register('out_of_window_policy')}
                    className={`h-4 w-4 ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
                  />
                  <span className={`text-sm ${textColors.secondary}`}>
                    {t(`refund-policies:fields.out_of_window_policy.options.${opt}`)}
                  </span>
                </label>
              ))}
            </div>
            <p className={tokens.helperText.base}>
              {t('refund-policies:fields.out_of_window_policy.help')}
            </p>
          </div>
        </RefundPoliciesSection>

        {/* ── Section 2: Manager override ──────────────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.managerOverride')}>
          <FieldRow
            label={t('refund-policies:fields.manager_override_threshold_amount.label')}
            help={t('refund-policies:fields.manager_override_threshold_amount.help')}
            error={errors.manager_override_threshold_amount?.message}
          >
            <Input
              type="text"
              inputMode="decimal"
              data-testid="field-manager_override_threshold_amount"
              {...register('manager_override_threshold_amount')}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.manager_override_threshold_percent.label')}
            help={t('refund-policies:fields.manager_override_threshold_percent.help')}
            error={errors.manager_override_threshold_percent?.message}
          >
            <Input
              type="text"
              inputMode="decimal"
              data-testid="field-manager_override_threshold_percent"
              {...register('manager_override_threshold_percent')}
            />
          </FieldRow>

          <div className="flex items-center justify-between">
            <div className="flex-1">
              <p className={`text-sm font-medium ${textColors.secondary}`}>
                {t('refund-policies:fields.manager_override_required_for_no_receipt.label')}
              </p>
              <p className={tokens.helperText.base}>
                {t('refund-policies:fields.manager_override_required_for_no_receipt.help')}
              </p>
            </div>
            <Controller
              name="manager_override_required_for_no_receipt"
              control={control}
              render={({ field }) => (
                <Toggle
                  checked={field.value ?? false}
                  onChange={field.onChange}
                  data-testid="field-manager_override_required_for_no_receipt"
                />
              )}
            />
          </div>
        </RefundPoliciesSection>

        {/* ── Section 3: Destinations & proration ──────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.destinationsProration')}>
          <div>
            <label className={tokens.label.base}>
              {t('refund-policies:fields.allowed_refund_destinations.label')}
            </label>
            <div className="mt-2 space-y-2">
              <Controller
                name="allowed_refund_destinations"
                control={control}
                render={({ field }) => (
                  <>
                    {(['original_payment', 'cash', 'store_voucher'] as RefundDestination[]).map(
                      (dest) => (
                        <label key={dest} className="flex items-center gap-3 cursor-pointer">
                          <Checkbox
                            data-testid={`destination-${dest}`}
                            checked={(field.value ?? []).includes(dest)}
                            onChange={() => {
                              toggleDestination(dest, field.value ?? [], field.onChange)
                            }}
                          />
                          <span className={`text-sm ${textColors.secondary}`}>
                            {t(`refund-policies:fields.allowed_refund_destinations.options.${dest}`)}
                          </span>
                        </label>
                      )
                    )}
                  </>
                )}
              />
            </div>
            {errors.allowed_refund_destinations && (
              <p className={tokens.helperText.error}>
                {errors.allowed_refund_destinations.message}
              </p>
            )}
            <p className={tokens.helperText.base}>
              {t('refund-policies:fields.allowed_refund_destinations.help')}
            </p>
          </div>

          <FieldRow
            label={t('refund-policies:fields.proration_strategy.label')}
            help={t('refund-policies:fields.proration_strategy.help')}
            error={errors.proration_strategy?.message}
          >
            <Select
              data-testid="field-proration_strategy"
              {...register('proration_strategy')}
            >
              {(['proportional', 'largest_first', 'cashier_choice'] as const).map((opt) => (
                <option key={opt} value={opt}>
                  {t(`refund-policies:fields.proration_strategy.options.${opt}`)}
                </option>
              ))}
            </Select>
          </FieldRow>
        </RefundPoliciesSection>

        {/* ── Section 4: Voucher defaults ───────────────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.voucherDefaults')}>
          <FieldRow
            label={t('refund-policies:fields.voucher_default_expiry_days.label')}
            help={t('refund-policies:fields.voucher_default_expiry_days.help')}
            error={errors.voucher_default_expiry_days?.message}
          >
            <Input
              type="number"
              min={1}
              max={3650}
              data-testid="field-voucher_default_expiry_days"
              {...register('voucher_default_expiry_days', { valueAsNumber: true })}
            />
          </FieldRow>

          <div className="flex items-center justify-between">
            <div className="flex-1">
              <p className={`text-sm font-medium ${textColors.secondary}`}>
                {t('refund-policies:fields.voucher_transferable_default.label')}
              </p>
              <p className={tokens.helperText.base}>
                {t('refund-policies:fields.voucher_transferable_default.help')}
              </p>
            </div>
            <Controller
              name="voucher_transferable_default"
              control={control}
              render={({ field }) => (
                <Toggle
                  checked={field.value ?? false}
                  onChange={field.onChange}
                  data-testid="field-voucher_transferable_default"
                />
              )}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="flex-1">
              <p className={`text-sm font-medium ${textColors.secondary}`}>
                {t('refund-policies:fields.voucher_cash_refund_allowed.label')}
              </p>
              <p className={tokens.helperText.base}>
                {t('refund-policies:fields.voucher_cash_refund_allowed.help')}
              </p>
            </div>
            <Controller
              name="voucher_cash_refund_allowed"
              control={control}
              render={({ field }) => (
                <Toggle
                  checked={field.value ?? false}
                  onChange={field.onChange}
                  data-testid="field-voucher_cash_refund_allowed"
                />
              )}
            />
          </div>
        </RefundPoliciesSection>

        {/* ── Section 5: Daily caps ─────────────────────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.dailyCaps')}>
          {/* daily_refund_cap_per_cashier */}
          <div>
            <Controller
              name="daily_refund_cap_per_cashier"
              control={control}
              render={({ field }) => {
                const isEnabled = field.value !== null
                return (
                  <>
                    <div className="flex items-center gap-3 mb-2">
                      <Checkbox
                        id="daily-cap-cashier-enable-ctrl"
                        data-testid="daily-cap-cashier-enable"
                        checked={isEnabled}
                        onChange={(e) => {
                          field.onChange(e.target.checked ? '' : null)
                        }}
                      />
                      <label
                        htmlFor="daily-cap-cashier-enable-ctrl"
                        className={`text-sm font-medium ${textColors.secondary} cursor-pointer`}
                      >
                        {t('refund-policies:fields.daily_refund_cap_per_cashier.enableLabel')}
                      </label>
                    </div>
                    {isEnabled && (
                      <Input
                        type="text"
                        inputMode="decimal"
                        data-testid="field-daily_refund_cap_per_cashier"
                        value={field.value ?? ''}
                        onChange={(e) => { field.onChange(e.target.value) }}
                      />
                    )}
                  </>
                )
              }}
            />
            <p className={tokens.helperText.base}>
              {t('refund-policies:fields.daily_refund_cap_per_cashier.help')}
            </p>
          </div>

          <div className="flex items-center justify-between">
            <div className="flex-1">
              <p className={`text-sm font-medium ${textColors.secondary}`}>
                {t('refund-policies:fields.daily_refund_cap_override_allowed.label')}
              </p>
              <p className={tokens.helperText.base}>
                {t('refund-policies:fields.daily_refund_cap_override_allowed.help')}
              </p>
            </div>
            <Controller
              name="daily_refund_cap_override_allowed"
              control={control}
              render={({ field }) => (
                <Toggle
                  checked={field.value ?? false}
                  onChange={field.onChange}
                  data-testid="field-daily_refund_cap_override_allowed"
                />
              )}
            />
          </div>
        </RefundPoliciesSection>

        {/* ── Section 6: Customer-history privacy ──────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.customerHistoryPrivacy')}>
          <FieldRow
            label={t('refund-policies:fields.customer_history_search_max_per_cashier_per_day.label')}
            help={t('refund-policies:fields.customer_history_search_max_per_cashier_per_day.help')}
            error={errors.customer_history_search_max_per_cashier_per_day?.message}
          >
            <Input
              type="number"
              min={0}
              max={1000}
              data-testid="field-customer_history_search_max_per_cashier_per_day"
              {...register('customer_history_search_max_per_cashier_per_day', {
                valueAsNumber: true,
              })}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.customer_history_search_alert_thresholds.rejected_specificity_per_hour.label')}
            help={t('refund-policies:fields.customer_history_search_alert_thresholds.rejected_specificity_per_hour.help')}
            error={errors.customer_history_search_alert_thresholds?.rejected_specificity_per_hour?.message}
          >
            <Input
              type="number"
              min={0}
              data-testid="field-alert_rejected_specificity_per_hour"
              {...register('customer_history_search_alert_thresholds.rejected_specificity_per_hour', {
                valueAsNumber: true,
              })}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.customer_history_search_alert_thresholds.same_partner_per_day.label')}
            help={t('refund-policies:fields.customer_history_search_alert_thresholds.same_partner_per_day.help')}
            error={errors.customer_history_search_alert_thresholds?.same_partner_per_day?.message}
          >
            <Input
              type="number"
              min={0}
              data-testid="field-alert_same_partner_per_day"
              {...register('customer_history_search_alert_thresholds.same_partner_per_day', {
                valueAsNumber: true,
              })}
            />
          </FieldRow>

          <div className="flex items-center justify-between">
            <div className="flex-1">
              <p className={`text-sm font-medium ${textColors.secondary}`}>
                {t('refund-policies:fields.customer_history_search_alert_thresholds.cross_company_immediate.label')}
              </p>
              <p className={tokens.helperText.base}>
                {t('refund-policies:fields.customer_history_search_alert_thresholds.cross_company_immediate.help')}
              </p>
            </div>
            <Controller
              name="customer_history_search_alert_thresholds.cross_company_immediate"
              control={control}
              render={({ field }) => (
                <Toggle
                  checked={field.value ?? false}
                  onChange={field.onChange}
                  data-testid="field-alert_cross_company_immediate"
                />
              )}
            />
          </div>
        </RefundPoliciesSection>

        {/* ── Section 7: Voucher rate limits ────────────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.voucherRateLimits')}>
          <FieldRow
            label={t('refund-policies:fields.voucher_lookup_per_terminal_per_day.label')}
            help={t('refund-policies:fields.voucher_lookup_per_terminal_per_day.help')}
            error={errors.voucher_lookup_per_terminal_per_day?.message}
          >
            <Input
              type="number"
              min={0}
              max={10000}
              data-testid="field-voucher_lookup_per_terminal_per_day"
              {...register('voucher_lookup_per_terminal_per_day', { valueAsNumber: true })}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.voucher_lookup_per_cashier_per_day.label')}
            help={t('refund-policies:fields.voucher_lookup_per_cashier_per_day.help')}
            error={errors.voucher_lookup_per_cashier_per_day?.message}
          >
            <Input
              type="number"
              min={0}
              max={10000}
              data-testid="field-voucher_lookup_per_cashier_per_day"
              {...register('voucher_lookup_per_cashier_per_day', { valueAsNumber: true })}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.voucher_lookup_failed_per_tenant_per_hour_alert.label')}
            help={t('refund-policies:fields.voucher_lookup_failed_per_tenant_per_hour_alert.help')}
            error={errors.voucher_lookup_failed_per_tenant_per_hour_alert?.message}
          >
            <Input
              type="number"
              min={0}
              max={10000}
              data-testid="field-voucher_lookup_failed_per_tenant_per_hour_alert"
              {...register('voucher_lookup_failed_per_tenant_per_hour_alert', { valueAsNumber: true })}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.voucher_lookup_failed_per_tenant_per_hour_block.label')}
            help={t('refund-policies:fields.voucher_lookup_failed_per_tenant_per_hour_block.help')}
            error={errors.voucher_lookup_failed_per_tenant_per_hour_block?.message}
          >
            <Input
              type="number"
              min={0}
              max={10000}
              data-testid="field-voucher_lookup_failed_per_tenant_per_hour_block"
              {...register('voucher_lookup_failed_per_tenant_per_hour_block', { valueAsNumber: true })}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.voucher_failed_attempts_auto_void.label')}
            help={t('refund-policies:fields.voucher_failed_attempts_auto_void.help')}
            error={errors.voucher_failed_attempts_auto_void?.message}
          >
            <Input
              type="number"
              min={1}
              max={100}
              data-testid="field-voucher_failed_attempts_auto_void"
              {...register('voucher_failed_attempts_auto_void', { valueAsNumber: true })}
            />
          </FieldRow>
        </RefundPoliciesSection>

        {/* ── Section 8: Goodwill controls ─────────────────────────────── */}
        <RefundPoliciesSection title={t('refund-policies:sections.goodwillControls')}>
          <FieldRow
            label={t('refund-policies:fields.goodwill_named_customer_threshold.label')}
            help={t('refund-policies:fields.goodwill_named_customer_threshold.help')}
            error={errors.goodwill_named_customer_threshold?.message}
          >
            <Input
              type="text"
              inputMode="decimal"
              data-testid="field-goodwill_named_customer_threshold"
              {...register('goodwill_named_customer_threshold')}
            />
          </FieldRow>

          <FieldRow
            label={t('refund-policies:fields.goodwill_four_eyes_threshold.label')}
            help={t('refund-policies:fields.goodwill_four_eyes_threshold.help')}
            error={errors.goodwill_four_eyes_threshold?.message}
          >
            <Input
              type="text"
              inputMode="decimal"
              data-testid="field-goodwill_four_eyes_threshold"
              {...register('goodwill_four_eyes_threshold')}
            />
          </FieldRow>

          {/* goodwill_daily_issuance_cap_per_user — nullable with toggle */}
          <div>
            <Controller
              name="goodwill_daily_issuance_cap_per_user"
              control={control}
              render={({ field }) => {
                const isEnabled = field.value !== null
                return (
                  <>
                    <div className="flex items-center gap-3 mb-2">
                      <Checkbox
                        id="goodwill-daily-cap-enable"
                        data-testid="goodwill-daily-cap-enable"
                        checked={isEnabled}
                        onChange={(e) => {
                          field.onChange(e.target.checked ? '' : null)
                        }}
                      />
                      <label
                        htmlFor="goodwill-daily-cap-enable"
                        className={`text-sm font-medium ${textColors.secondary} cursor-pointer`}
                      >
                        {t('refund-policies:fields.goodwill_daily_issuance_cap_per_user.label')}
                      </label>
                    </div>
                    {isEnabled && (
                      <Input
                        type="text"
                        inputMode="decimal"
                        data-testid="field-goodwill_daily_issuance_cap_per_user"
                        value={field.value ?? ''}
                        onChange={(e) => { field.onChange(e.target.value) }}
                      />
                    )}
                    <p className={tokens.helperText.base}>
                      {t('refund-policies:fields.goodwill_daily_issuance_cap_per_user.help')}
                    </p>
                  </>
                )
              }}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="flex-1">
              <p className={`text-sm font-medium ${textColors.secondary}`}>
                {t('refund-policies:fields.goodwill_bearer_default_off.label')}
              </p>
              <p className={tokens.helperText.base}>
                {t('refund-policies:fields.goodwill_bearer_default_off.help')}
              </p>
            </div>
            <Controller
              name="goodwill_bearer_default_off"
              control={control}
              render={({ field }) => (
                <Toggle
                  checked={field.value ?? true}
                  onChange={field.onChange}
                  data-testid="field-goodwill_bearer_default_off"
                />
              )}
            />
          </div>
        </RefundPoliciesSection>

        {/* ── Sticky footer ─────────────────────────────────────────────── */}
        <div
          className={`sticky bottom-0 z-10 flex items-center justify-between gap-3 rounded-lg border ${borderColors.light} ${colors.white} px-6 py-4 shadow-md`}
        >
          <Button variant="secondary"
            type="button"
            data-testid="reset-button"
            onClick={handleReset}
            className="gap-2"
          >
            <RotateCcw className="h-4 w-4" />
            {t('refund-policies:actions.reset')}
          </Button>

          <Button
            type="submit"
            data-testid="save-button"
            disabled={!isDirty || updateMutation.isPending || !canEdit}
            title={canEdit ? undefined : t('common:permissions.readOnlyEditHint')}
            className="gap-2"
          >
            {updateMutation.isPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('refund-policies:actions.saving')}
              </>
            ) : (
              <>
                <Save className="h-4 w-4" />
                {t('refund-policies:actions.save')}
              </>
            )}
          </Button>
        </div>
      </form>
    </div>
  )
}
