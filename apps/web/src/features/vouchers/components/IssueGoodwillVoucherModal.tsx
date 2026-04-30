import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { AlertCircle, Info } from 'lucide-react'
import { Button, Input, FormField, Select, Textarea } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { PartnerPicker, type PartnerPickerValue } from '@/components/molecules/pickers/PartnerPicker'
import { UserPicker } from '@/components/ui/UserPicker'
import { useTerminals } from '@/features/pos/hooks/useTerminals'
import { useReservationSettings } from '../hooks/useReservationSettings'
import { useIssueGoodwill } from '../hooks/useVoucherMutations'
import type { RedemptionMode } from '../types/voucher'

// ─── Error code constants ─────────────────────────────────────────────────────

const DOMAIN_ERROR_CODES = {
  FOUR_EYES_REQUIRED: 'FOUR_EYES_REQUIRED',
  NAMED_CUSTOMER_REQUIRED: 'NAMED_CUSTOMER_REQUIRED',
  SELF_DEALING: 'SELF_DEALING',
} as const

// ─── Form schema ──────────────────────────────────────────────────────────────

const schema = z.object({
  amount: z.string().min(1).refine((v) => !isNaN(Number(v)) && Number(v) > 0, {
    message: 'Amount must be a positive number',
  }),
  currency: z.string().min(3).max(3),
  redemption_mode: z.enum(['Bearer', 'CustomerBound']),
  expires_at: z.string().optional(),
  notes: z.string().min(1),
  terminal_id: z.string().min(1),
  second_admin_user_id: z.string().optional(),
})

type FormValues = z.infer<typeof schema>

// ─── Component ───────────────────────────────────────────────────────────────

interface IssueGoodwillVoucherModalProps {
  isOpen: boolean
  onClose: () => void
}

export function IssueGoodwillVoucherModal({ isOpen, onClose }: IssueGoodwillVoucherModalProps) {
  const { t } = useTranslation(['vouchers', 'common'])
  const { data: settings } = useReservationSettings()
  const { data: terminalsData } = useTerminals()
  const mutation = useIssueGoodwill()

  const [partner, setPartner] = useState<PartnerPickerValue | null>(null)
  const [secondAdminId, setSecondAdminId] = useState<string | null>(null)
  const [secondAdminName, setSecondAdminName] = useState<string | null>(null)
  const [domainError, setDomainError] = useState<string | null>(null)

  const defaultRedemptionMode: RedemptionMode =
    settings?.goodwill_bearer_default_off ? 'CustomerBound' : 'Bearer'

  const threshold = settings ? Number(settings.goodwill_four_eyes_threshold) : Infinity

  const expiryDefault = (() => {
    if (!settings?.voucher_default_expiry_days) return ''
    const d = new Date()
    d.setDate(d.getDate() + settings.voucher_default_expiry_days)
    return d.toISOString().split('T')[0]
  })()

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      amount: '',
      currency: 'EUR',
      redemption_mode: defaultRedemptionMode,
      expires_at: expiryDefault,
      notes: '',
      terminal_id: '',
      second_admin_user_id: undefined,
    },
  })

  // Update defaults when settings load
  useEffect(() => {
    if (settings && isOpen) {
      form.setValue('redemption_mode', defaultRedemptionMode)
      form.setValue('expires_at', expiryDefault)
    }
  }, [settings, isOpen, defaultRedemptionMode, expiryDefault, form])

  const amountValue = form.watch('amount')
  const needsFourEyes = !isNaN(Number(amountValue)) && Number(amountValue) >= threshold

  // Clear secondAdmin state when amount drops below the four-eyes threshold
  useEffect(() => {
    if (!needsFourEyes) {
      setSecondAdminId(null)
      setSecondAdminName(null)
    }
  }, [needsFourEyes])

  const handleSubmit = (values: FormValues) => {
    setDomainError(null)

    if (needsFourEyes && !secondAdminId) {
      setDomainError(t('vouchers:errors.FOUR_EYES_REQUIRED'))
      return
    }

    mutation.mutate(
      {
        amount: values.amount,
        currency: values.currency,
        partner_id: partner?.id ?? null,
        redemption_mode: values.redemption_mode,
        expires_at: values.expires_at ?? null,
        notes: values.notes,
        terminal_id: values.terminal_id,
        second_admin_user_id: secondAdminId ?? null,
      },
      {
        onSuccess: () => {
          onClose()
          form.reset()
          setPartner(null)
          setSecondAdminId(null)
          setSecondAdminName(null)
        },
        onError: (error: unknown) => {
          const err = error as { response?: { data?: { error?: { code?: string } } } }
          const code = err?.response?.data?.error?.code
          if (code === DOMAIN_ERROR_CODES.FOUR_EYES_REQUIRED) {
            setDomainError(t('vouchers:errors.FOUR_EYES_REQUIRED'))
          } else if (code === DOMAIN_ERROR_CODES.NAMED_CUSTOMER_REQUIRED) {
            setDomainError(t('vouchers:errors.NAMED_CUSTOMER_REQUIRED'))
          } else if (code === DOMAIN_ERROR_CODES.SELF_DEALING) {
            setDomainError(t('vouchers:errors.SELF_DEALING'))
          }
        },
      },
    )
  }

  const terminals = terminalsData ?? []

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <Modal.Header title={t('vouchers:issueGoodwill.title')} onClose={onClose} />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          {domainError && (
            <div
              role="alert"
              className={`flex items-start gap-2 rounded-md p-3 ${colors.error[50]} ${borderColors.error} border`}
            >
              <AlertCircle className={`w-4 h-4 mt-0.5 shrink-0 ${textColors.error}`} />
              <p className={`text-sm ${textColors.error}`}>{domainError}</p>
            </div>
          )}

          <div className="grid grid-cols-2 gap-4">
            <FormField
              label={t('vouchers:fields.amount')}
              error={form.formState.errors.amount?.message}
            >
              <Input
                {...form.register('amount')}
                type="number"
                step="0.01"
                min="0.01"
                placeholder="0.00"
              />
            </FormField>

            <FormField
              label={t('vouchers:fields.currency')}
              error={form.formState.errors.currency?.message}
            >
              <Input
                {...form.register('currency')}
                maxLength={3}
                placeholder="EUR"
              />
            </FormField>
          </div>

          <FormField label={t('vouchers:fields.customer')}>
            <PartnerPicker
              value={partner}
              onChange={setPartner}
              partnerType="customer"
            />
          </FormField>

          <FormField label={t('vouchers:fields.redemptionMode')}>
            <div className="flex gap-4 mt-1">
              {(['Bearer', 'CustomerBound'] as RedemptionMode[]).map((mode) => (
                <label key={mode} className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="radio"
                    value={mode}
                    {...form.register('redemption_mode')}
                    className={tokens.radio.base}
                  />
                  <span className={`text-sm ${textColors.secondary}`}>{t(`vouchers:redemptionModes.${mode}`)}</span>
                </label>
              ))}
            </div>
          </FormField>

          <div className="grid grid-cols-2 gap-4">
            <FormField
              label={t('vouchers:fields.expiresAt')}
              error={form.formState.errors.expires_at?.message}
            >
              <Input {...form.register('expires_at')} type="date" />
            </FormField>

            <FormField
              label={t('vouchers:fields.terminal')}
              error={form.formState.errors.terminal_id?.message}
            >
              <Select {...form.register('terminal_id')} className="w-full">
                <option value="">{t('vouchers:issueGoodwill.selectTerminal')}</option>
                {terminals.map((terminal) => (
                  <option key={terminal.id} value={terminal.id}>
                    {terminal.name}
                  </option>
                ))}
              </Select>
            </FormField>
          </div>

          <FormField
            label={t('vouchers:fields.notes')}
            error={form.formState.errors.notes?.message}
          >
            <Textarea {...form.register('notes')} rows={3} />
          </FormField>

          {/* Four-eyes section — shown when amount meets or exceeds threshold */}
          {needsFourEyes && (
            <div className={`rounded-md ${colors.primary[50]} border ${borderColors.primary} p-4 space-y-3`}>
              <div className="flex items-start gap-2">
                <Info className={`w-4 h-4 mt-0.5 shrink-0 ${textColors.brand}`} />
                <p className={`text-sm ${textColors.brand}`}>
                  {t('vouchers:issueGoodwill.fourEyesInfo')}
                </p>
              </div>
              <FormField label={t('vouchers:issueGoodwill.secondAdmin')}>
                <UserPicker
                  value={secondAdminId}
                  selectedLabel={secondAdminName}
                  onChange={(id, name) => {
                    setSecondAdminId(id)
                    setSecondAdminName(name ?? null)
                  }}
                  roleFilter="admin"
                  testId="second-admin-picker"
                />
              </FormField>
            </div>
          )}
        </Modal.Content>

        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? t('common:saving') : t('vouchers:issueGoodwill.action')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
