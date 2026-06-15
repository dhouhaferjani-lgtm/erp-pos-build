import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Save, RotateCcw, Mail, Shield } from 'lucide-react'
import { toast } from 'sonner'
import { tokens, textColors } from '@/lib/designTokens'
import { Checkbox } from '@/components/atoms'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { usePermissions } from '../../../hooks/usePermissions'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { getFraudSettings, updateFraudSettings, resetFraudSettings } from '../api/fraudApi'
import { fraudSettingsInvalidationPredicate } from '../_invalidation'
import type { FraudSettings } from '../types/fraudSettings'
import { CashDrawerControlsSection } from '../components/CashDrawerControlsSection'
import type { CashDrawerControlsValue } from '../components/CashDrawerControlsSection'

type EmailSeverity = CashDrawerControlsValue['cash_variance_email_severity']

const ALLOWED_EMAIL_SEVERITIES: readonly EmailSeverity[] = ['none', 'critical', 'warning', 'info']

/**
 * Narrow the `cash_variance_email_severity` field from the generated DTO
 * (typed as `string`) into the literal union the cash-drawer control
 * component expects. Falls back to `'none'` for unknown / undefined values.
 */
function narrowEmailSeverity(value: string | null | undefined): EmailSeverity {
  return ALLOWED_EMAIL_SEVERITIES.includes(value as EmailSeverity)
    ? (value as EmailSeverity)
    : 'none'
}

export function FraudSettingsPage() {
  const { t } = useTranslation(['common', 'compliance'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const { hasPermission } = usePermissions()
  const canEdit = hasPermission('pos.configure_cash_count')

  const [formData, setFormData] = useState<Partial<FraudSettings>>({
    abandoned_draft_threshold: 5,
    time_window_days: 30,
    alert_emails: [],
    alert_enabled: true,
    auto_trigger_counting: true,
    auto_restrict_access: false,
    cash_variance_over_soft: '1.00',
    cash_variance_over_hard: '20.00',
    cash_variance_under_soft: '1.00',
    cash_variance_under_hard: '20.00',
    require_blind_cash_count: false,
    require_manager_pin_above_hard: true,
    cash_variance_email_severity: 'none',
  })

  const [emailInput, setEmailInput] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [showResetConfirm, setShowResetConfirm] = useState(false)

  // Fetch current settings
  const { data: settingsData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['fraud-settings']),
    queryFn: getFraudSettings,
    enabled: !!tenantId && !!companyId,
  })

  // Update form when data loads
  useEffect(() => {
    if (settingsData?.data) {
      setFormData(settingsData.data)
    }
  }, [settingsData])

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: updateFraudSettings,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: fraudSettingsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(t('compliance:fraudSettings.messages.updateSuccess'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.generic'))
    },
  })

  // Reset mutation
  const resetMutation = useMutation({
    mutationFn: resetFraudSettings,
    onSuccess: async (data) => {
      setFormData(data.data)
      await queryClient.invalidateQueries({
        predicate: fraudSettingsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(t('compliance:fraudSettings.messages.resetSuccess'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.generic'))
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    // Validate
    const newErrors: Record<string, string> = {}

    if ((formData.abandoned_draft_threshold ?? 0) < 1 || (formData.abandoned_draft_threshold ?? 0) > 100) {
      newErrors['abandoned_draft_threshold'] = t('compliance:fraudSettings.errors.thresholdRange')
    }

    if ((formData.time_window_days ?? 0) < 1 || (formData.time_window_days ?? 0) > 365) {
      newErrors['time_window_days'] = t('compliance:fraudSettings.errors.timeWindowRange')
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors)
      return
    }

    setErrors({})
    updateMutation.mutate(formData)
  }

  const handleAddEmail = () => {
    const email = emailInput.trim()

    if (!email) return

    // Basic email validation
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setErrors({ ...errors, ['email']: t('compliance:fraudSettings.errors.invalidEmail') })
      return
    }

    if (formData.alert_emails?.includes(email)) {
      setErrors({ ...errors, ['email']: t('compliance:fraudSettings.errors.duplicateEmail') })
      return
    }

    setFormData({
      ...formData,
      alert_emails: [...(formData.alert_emails || []), email],
    })
    setEmailInput('')
    setErrors({ ...errors, ['email']: '' })
  }

  const handleRemoveEmail = (email: string) => {
    setFormData({
      ...formData,
      alert_emails: formData.alert_emails?.filter((e) => e !== email) || [],
    })
  }

  const handleResetClick = () => {
    setShowResetConfirm(true)
  }

  const handleResetConfirm = () => {
    resetMutation.mutate()
    setShowResetConfirm(false)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className={textColors.tertiary}>{t('common:loading')}</div>
      </div>
    )
  }


  const isConfigured = settingsData?.data.is_configured ?? false

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <div className="flex items-center justify-between">
          <div>
            <h1 className={`text-2xl font-bold ${textColors.primary} flex items-center gap-2`}>
              <Shield className={`h-6 w-6 ${textColors.disabled}`} />
              {t('compliance:fraudSettings.title')}
            </h1>
            <p className={`${textColors.tertiary} mt-1`}>
              {t('compliance:fraudSettings.description')}
            </p>
          </div>

          {isConfigured && (
            <button
              type="button"
              onClick={handleResetClick}
              disabled={resetMutation.isPending}
              className={`flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg disabled:opacity-50 ${tokens.button.secondary}`}
            >
              <RotateCcw className="h-4 w-4" />
              {t('compliance:fraudSettings.actions.reset')}
            </button>
          )}
        </div>

        {!isConfigured && (
          <div className="mt-4 bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-start gap-3">
            <AlertTriangle className="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" />
            <div>
              <h3 className="font-medium text-amber-900">
                {t('compliance:fraudSettings.notConfigured.title')}
              </h3>
              <p className="text-sm text-amber-800 mt-1">
                {t('compliance:fraudSettings.notConfigured.description')}
              </p>
            </div>
          </div>
        )}
      </div>

      {/* Settings Form */}
      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Detection Thresholds */}
        <div className={tokens.card.base}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4`}>
            {t('compliance:fraudSettings.sections.detection')}
          </h2>

          <div className="grid gap-6 md:grid-cols-2">
            <div>
              <label htmlFor="threshold" className={`${tokens.label.base} mb-1`}>
                {t('compliance:fraudSettings.fields.threshold.label')}
              </label>
              <input
                type="number"
                id="threshold"
                min="1"
                max="100"
                value={formData.abandoned_draft_threshold || 5}
                onChange={(e) => { setFormData({ ...formData, abandoned_draft_threshold: parseInt(e.target.value) }); }}
                className={tokens.input.base}
              />
              <p className={`${tokens.helperText.base}`}>
                {t('compliance:fraudSettings.fields.threshold.hint')}
              </p>
              {errors['abandoned_draft_threshold'] && (
                <p className={tokens.helperText.error}>{errors['abandoned_draft_threshold']}</p>
              )}
            </div>

            <div>
              <label htmlFor="timeWindow" className={`${tokens.label.base} mb-1`}>
                {t('compliance:fraudSettings.fields.timeWindow.label')}
              </label>
              <input
                type="number"
                id="timeWindow"
                min="1"
                max="365"
                value={formData.time_window_days || 30}
                onChange={(e) => { setFormData({ ...formData, time_window_days: parseInt(e.target.value) }); }}
                className={tokens.input.base}
              />
              <p className={tokens.helperText.base}>
                {t('compliance:fraudSettings.fields.timeWindow.hint')}
              </p>
              {errors['time_window_days'] && (
                <p className={tokens.helperText.error}>{errors['time_window_days']}</p>
              )}
            </div>
          </div>
        </div>

        {/* Alert Configuration */}
        <div className={tokens.card.base}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4 flex items-center gap-2`}>
            <Mail className={`h-5 w-5 ${textColors.disabled}`} />
            {t('compliance:fraudSettings.sections.alerts')}
          </h2>

          <div className="space-y-4">
            <div className="flex items-center">
              <Checkbox
                id="alertEnabled"
                checked={formData.alert_enabled ?? true}
                onChange={(e) => { setFormData({ ...formData, alert_enabled: e.target.checked }); }}
              />
              <label htmlFor="alertEnabled" className={`ms-2 text-sm font-medium ${textColors.secondary}`}>
                {t('compliance:fraudSettings.fields.alertEnabled.label')}
              </label>
            </div>

            <div>
              <label htmlFor="emailInput" className={`${tokens.label.base} mb-1`}>
                {t('compliance:fraudSettings.fields.emails.label')}
              </label>
              <div className="flex gap-2">
                <input
                  type="email"
                  id="emailInput"
                  value={emailInput}
                  onChange={(e) => { setEmailInput(e.target.value); }}
                  onKeyPress={(e) => e.key === 'Enter' && (e.preventDefault(), handleAddEmail())}
                  placeholder={t('compliance:fraudSettings.fields.emails.placeholder')}
                  className={`flex-1 ${tokens.input.base}`}
                />
                <button
                  type="button"
                  onClick={handleAddEmail}
                  className={`px-4 py-2 text-sm font-medium rounded-lg ${tokens.button.primary}`}
                >
                  {t('common:actions.add')}
                </button>
              </div>
              <p className={tokens.helperText.base}>
                {t('compliance:fraudSettings.fields.emails.hint')}
              </p>
              {errors['email'] && (
                <p className={tokens.helperText.error}>{errors['email']}</p>
              )}
            </div>

            {(formData.alert_emails && formData.alert_emails.length > 0) && (
              <div className="flex flex-wrap gap-2">
                {formData.alert_emails.map((email) => (
                  <span
                    key={email}
                    className={`inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm ${tokens.badge.blue}`}
                  >
                    {email}
                    <button
                      type="button"
                      onClick={() => { handleRemoveEmail(email); }}
                      className="hover:text-blue-900"
                    >
                      ×
                    </button>
                  </span>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Auto Actions */}
        <div className={tokens.card.base}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4`}>
            {t('compliance:fraudSettings.sections.actions')}
          </h2>

          <div className="space-y-3">
            <div className="flex items-start">
              <Checkbox
                id="autoCounting"
                checked={formData.auto_trigger_counting ?? true}
                onChange={(e) => { setFormData({ ...formData, auto_trigger_counting: e.target.checked }); }}
                className="mt-1"
              />
              <label htmlFor="autoCounting" className="ms-2">
                <span className={`text-sm font-medium ${textColors.secondary} block`}>
                  {t('compliance:fraudSettings.fields.autoCounting.label')}
                </span>
                <span className={`text-sm ${textColors.tertiary}`}>
                  {t('compliance:fraudSettings.fields.autoCounting.hint')}
                </span>
              </label>
            </div>

            <div className="flex items-start">
              <Checkbox
                id="autoRestrict"
                checked={formData.auto_restrict_access ?? false}
                onChange={(e) => { setFormData({ ...formData, auto_restrict_access: e.target.checked }); }}
                className="mt-1"
              />
              <label htmlFor="autoRestrict" className="ms-2">
                <span className={`text-sm font-medium ${textColors.secondary} block`}>
                  {t('compliance:fraudSettings.fields.autoRestrict.label')}
                </span>
                <span className={`text-sm ${textColors.tertiary}`}>
                  {t('compliance:fraudSettings.fields.autoRestrict.hint')}
                </span>
              </label>
            </div>
          </div>
        </div>

        {/* Cash Drawer Controls */}
        <CashDrawerControlsSection
          value={{
            cash_variance_over_soft: formData.cash_variance_over_soft ?? '1.00',
            cash_variance_over_hard: formData.cash_variance_over_hard ?? '20.00',
            cash_variance_under_soft: formData.cash_variance_under_soft ?? '1.00',
            cash_variance_under_hard: formData.cash_variance_under_hard ?? '20.00',
            require_blind_cash_count: formData.require_blind_cash_count ?? false,
            require_manager_pin_above_hard: formData.require_manager_pin_above_hard ?? true,
            cash_variance_email_severity: narrowEmailSeverity(formData.cash_variance_email_severity),
          }}
          currencyCode="EUR"
          onChange={(next: CashDrawerControlsValue) => {
            setFormData({ ...formData, ...next })
          }}
          canEdit={canEdit}
        />

        {/* Actions */}
        <div className="flex justify-end gap-3">
          <button
            type="submit"
            disabled={updateMutation.isPending}
            className={`flex items-center gap-2 px-6 py-2 text-sm font-medium rounded-lg disabled:opacity-50 ${tokens.button.primary}`}
          >
            <Save className="h-4 w-4" />
            {updateMutation.isPending ? t('common:actions.saving') : t('common:actions.save')}
          </button>
        </div>
      </form>

      {/* Reset Confirmation Dialog */}
      <ConfirmDialog
        isOpen={showResetConfirm}
        onClose={() => { setShowResetConfirm(false); }}
        onConfirm={handleResetConfirm}
        title={t('compliance:fraudSettings.actions.reset')}
        message={t('compliance:fraudSettings.messages.confirmReset')}
        variant="warning"
        isLoading={resetMutation.isPending}
      />
    </div>
  )
}
