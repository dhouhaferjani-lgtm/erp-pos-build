import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { tokens, textColors } from '@/lib/designTokens'
import { bccomp } from '@/lib/decimal'
import { MoneyInput } from '@/components/atoms/MoneyInput'
import { Checkbox, Select } from '@/components/atoms'

export interface CashDrawerControlsValue {
  cash_variance_over_soft: string
  cash_variance_over_hard: string
  cash_variance_under_soft: string
  cash_variance_under_hard: string
  require_blind_cash_count: boolean
  require_manager_pin_above_hard: boolean
  cash_variance_email_severity: 'none' | 'critical' | 'warning' | 'info'
}

export interface CashDrawerControlsSectionProps {
  value: CashDrawerControlsValue
  currencyCode: string
  onChange: (next: CashDrawerControlsValue) => void
  canEdit: boolean
}

function isOverSoftGteHard(overSoft: string, overHard: string): boolean {
  // Use bccomp (Big.js) to avoid IEEE 754 float imprecision on monetary comparisons.
  // bccomp returns 0 when equal and 1 when a > b — both are invalid (soft must be < hard).
  return overSoft !== '' && overHard !== '' && bccomp(overSoft, overHard) >= 0
}

function isUnderSoftGteHard(underSoft: string, underHard: string): boolean {
  return underSoft !== '' && underHard !== '' && bccomp(underSoft, underHard) >= 0
}

type EmailSeverity = 'none' | 'critical' | 'warning' | 'info'

const SEVERITY_OPTIONS: EmailSeverity[] = ['none', 'critical', 'warning', 'info']

export function CashDrawerControlsSection({
  value,
  currencyCode,
  onChange,
  canEdit,
}: CashDrawerControlsSectionProps) {
  const { t } = useTranslation('compliance')

  // Local state for controlled inputs (so validation is live without parent updating prop)
  const [overSoft, setOverSoft] = useState(value.cash_variance_over_soft)
  const [overHard, setOverHard] = useState(value.cash_variance_over_hard)
  const [underSoft, setUnderSoft] = useState(value.cash_variance_under_soft)
  const [underHard, setUnderHard] = useState(value.cash_variance_under_hard)
  const [blindCount, setBlindCount] = useState(value.require_blind_cash_count)
  const [managerPin, setManagerPin] = useState(value.require_manager_pin_above_hard)
  const [emailSeverity, setEmailSeverity] = useState<EmailSeverity>(value.cash_variance_email_severity)

  const initialSymmetric =
    value.cash_variance_over_soft === value.cash_variance_under_soft &&
    value.cash_variance_over_hard === value.cash_variance_under_hard

  const [symmetric, setSymmetric] = useState(initialSymmetric)

  // Sync from parent when value prop changes (e.g. on load from server)
  useEffect(() => {
    setOverSoft(value.cash_variance_over_soft)
    setOverHard(value.cash_variance_over_hard)
    setUnderSoft(value.cash_variance_under_soft)
    setUnderHard(value.cash_variance_under_hard)
    setBlindCount(value.require_blind_cash_count)
    setManagerPin(value.require_manager_pin_above_hard)
    setEmailSeverity(value.cash_variance_email_severity)
  }, [
    value.cash_variance_over_soft,
    value.cash_variance_over_hard,
    value.cash_variance_under_soft,
    value.cash_variance_under_hard,
    value.require_blind_cash_count,
    value.require_manager_pin_above_hard,
    value.cash_variance_email_severity,
  ])

  const showError =
    isOverSoftGteHard(overSoft, overHard) ||
    isUnderSoftGteHard(underSoft, underHard)

  function buildNext(patch: Partial<{
    overSoft: string
    overHard: string
    underSoft: string
    underHard: string
    blindCount: boolean
    managerPin: boolean
    emailSeverity: EmailSeverity
  }>): CashDrawerControlsValue {
    return {
      cash_variance_over_soft: patch.overSoft ?? overSoft,
      cash_variance_over_hard: patch.overHard ?? overHard,
      cash_variance_under_soft: patch.underSoft ?? underSoft,
      cash_variance_under_hard: patch.underHard ?? underHard,
      require_blind_cash_count: patch.blindCount ?? blindCount,
      require_manager_pin_above_hard: patch.managerPin ?? managerPin,
      cash_variance_email_severity: patch.emailSeverity ?? emailSeverity,
    }
  }

  function handleSymmetricChange(checked: boolean) {
    setSymmetric(checked)
    if (checked) {
      setUnderSoft(overSoft)
      setUnderHard(overHard)
      onChange(buildNext({ underSoft: overSoft, underHard: overHard }))
    }
  }

  function handleOverSoftChange(raw: string) {
    setOverSoft(raw)
    if (symmetric) {
      setUnderSoft(raw)
      onChange(buildNext({ overSoft: raw, underSoft: raw }))
    } else {
      onChange(buildNext({ overSoft: raw }))
    }
  }

  function handleOverHardChange(raw: string) {
    setOverHard(raw)
    if (symmetric) {
      setUnderHard(raw)
      onChange(buildNext({ overHard: raw, underHard: raw }))
    } else {
      onChange(buildNext({ overHard: raw }))
    }
  }

  function handleUnderSoftChange(raw: string) {
    setUnderSoft(raw)
    onChange(buildNext({ underSoft: raw }))
  }

  function handleUnderHardChange(raw: string) {
    setUnderHard(raw)
    onChange(buildNext({ underHard: raw }))
  }

  function handleBlindCountChange(checked: boolean) {
    setBlindCount(checked)
    onChange(buildNext({ blindCount: checked }))
  }

  function handleManagerPinChange(checked: boolean) {
    setManagerPin(checked)
    onChange(buildNext({ managerPin: checked }))
  }

  function handleEmailSeverityChange(raw: string) {
    const severity: EmailSeverity = (SEVERITY_OPTIONS as string[]).includes(raw)
      ? (raw as EmailSeverity)
      : 'none'
    setEmailSeverity(severity)
    onChange(buildNext({ emailSeverity: severity }))
  }

  // MoneyInput derives the currency-aware step (TND → 0.001, EUR/USD → 0.01)
  // and emits canonical string values, so no manual step/parseFloat handling
  // is needed here. `text-right` keeps the numeric alignment of the originals.
  const inputClassName = 'text-right'

  return (
    <section
      data-testid="cash-controls-section"
      className={`${tokens.card.base} space-y-5`}
    >
      <h2 className={`text-lg font-semibold ${textColors.primary}`}>
        {t('fraudSettings.cashControls.sectionTitle')}
      </h2>

      {/* Symmetric toggle */}
      <div className="flex items-center gap-2">
        <Checkbox
          id="cash-symmetric-toggle"
          checked={symmetric}
          disabled={!canEdit}
          onChange={(e) => { handleSymmetricChange(e.target.checked) }}
        />
        <label htmlFor="cash-symmetric-toggle" className={`text-sm ${textColors.secondary}`}>
          {t('fraudSettings.cashControls.useSameToggle')}
        </label>
      </div>

      {/* Threshold inputs */}
      {symmetric ? (
        <div className="grid gap-4 md:grid-cols-2">
          <div>
            <label htmlFor="cash-over-soft" className={tokens.label.base}>
              {t('fraudSettings.cashControls.softLabel')} ({currencyCode})
            </label>
            <MoneyInput
              id="cash-over-soft"
              currency={currencyCode}
              value={overSoft}
              disabled={!canEdit}
              onChange={handleOverSoftChange}
              className={inputClassName}
            />
          </div>
          <div>
            <label htmlFor="cash-over-hard" className={tokens.label.base}>
              {t('fraudSettings.cashControls.hardLabel')} ({currencyCode})
            </label>
            <MoneyInput
              id="cash-over-hard"
              currency={currencyCode}
              value={overHard}
              disabled={!canEdit}
              onChange={handleOverHardChange}
              className={inputClassName}
            />
          </div>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          <div>
            <label htmlFor="cash-over-soft" className={tokens.label.base}>
              {t('fraudSettings.cashControls.overSoftLabel')} ({currencyCode})
            </label>
            <MoneyInput
              id="cash-over-soft"
              currency={currencyCode}
              value={overSoft}
              disabled={!canEdit}
              onChange={handleOverSoftChange}
              className={inputClassName}
            />
          </div>
          <div>
            <label htmlFor="cash-over-hard" className={tokens.label.base}>
              {t('fraudSettings.cashControls.overHardLabel')} ({currencyCode})
            </label>
            <MoneyInput
              id="cash-over-hard"
              currency={currencyCode}
              value={overHard}
              disabled={!canEdit}
              onChange={handleOverHardChange}
              className={inputClassName}
            />
          </div>
          <div>
            <label htmlFor="cash-under-soft" className={tokens.label.base}>
              {t('fraudSettings.cashControls.underSoftLabel')} ({currencyCode})
            </label>
            <MoneyInput
              id="cash-under-soft"
              currency={currencyCode}
              value={underSoft}
              disabled={!canEdit}
              onChange={handleUnderSoftChange}
              className={inputClassName}
            />
          </div>
          <div>
            <label htmlFor="cash-under-hard" className={tokens.label.base}>
              {t('fraudSettings.cashControls.underHardLabel')} ({currencyCode})
            </label>
            <MoneyInput
              id="cash-under-hard"
              currency={currencyCode}
              value={underHard}
              disabled={!canEdit}
              onChange={handleUnderHardChange}
              className={inputClassName}
            />
          </div>
        </div>
      )}

      {/* Validation error */}
      {showError && (
        <p
          data-testid="cash-controls-error"
          className={tokens.helperText.error}
        >
          {t('fraudSettings.cashControls.softGteHard')}
        </p>
      )}

      {/* Checkboxes */}
      <div className="space-y-3 pt-2">
        <div className="flex items-start gap-2">
          <Checkbox
            id="cash-blind-count"
            checked={blindCount}
            disabled={!canEdit}
            onChange={(e) => { handleBlindCountChange(e.target.checked) }}
            className="mt-0.5"
          />
          <label htmlFor="cash-blind-count" className={`text-sm ${textColors.secondary}`}>
            {t('fraudSettings.cashControls.blindCountLabel')}
          </label>
        </div>

        <div className="flex items-start gap-2">
          <Checkbox
            id="cash-manager-pin"
            checked={managerPin}
            disabled={!canEdit}
            onChange={(e) => { handleManagerPinChange(e.target.checked) }}
            className="mt-0.5"
          />
          <label htmlFor="cash-manager-pin" className={`text-sm ${textColors.secondary}`}>
            {t('fraudSettings.cashControls.managerPinAboveHardLabel')}
          </label>
        </div>
      </div>

      {/* Email severity select */}
      <div>
        <label htmlFor="cash-email-severity" className={tokens.label.base}>
          {t('fraudSettings.cashControls.emailSeverityLabel')}
        </label>
        <Select
          id="cash-email-severity"
          value={emailSeverity}
          disabled={!canEdit}
          onChange={(e) => { handleEmailSeverityChange(e.target.value) }}
        >
          <option value="none">{t('fraudSettings.cashControls.emailSeverityNever')}</option>
          <option value="critical">{t('fraudSettings.cashControls.emailSeverityCritical')}</option>
          <option value="warning">{t('fraudSettings.cashControls.emailSeverityWarning')}</option>
          <option value="info">{t('fraudSettings.cashControls.emailSeverityInfo')}</option>
        </Select>
      </div>
    </section>
  )
}
