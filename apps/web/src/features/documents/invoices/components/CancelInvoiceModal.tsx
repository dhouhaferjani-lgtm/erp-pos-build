import { useMemo } from 'react'
import { useForm, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { AlertCircle } from 'lucide-react'
import { Modal } from '@/components/organisms/Modal/Modal'
import { Button } from '@/components/atoms/Button/Button'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Radio } from '@/components/atoms/Radio/Radio'
import { Input } from '@/components/atoms/Input/Input'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { semanticColorTokens, textColors, borderColors } from '@/lib/designTokens'
import { CancelErrorCodes, type ReturnDecisionMode } from '../api/cancelInvoice'
import type { CanCancelResponse } from '../hooks/useCancelInvoice'

/** The radio the user clicks. What gets POSTED depends on the branch — see below. */
type GoodsOption = 'will_return' | 'already_returned' | 'no_return'

export interface CancelInvoiceModalProps {
  isOpen: boolean
  onClose: () => void
  invoiceNumber: string
  canCancel: CanCancelResponse | undefined
  isSubmitting: boolean
  /** The typed refusal from the last attempt, if any. */
  errorCode?: string | undefined
  errorDetails?: Record<string, unknown> | undefined
  onSubmit: (input: { reason: string; mode: ReturnDecisionMode; returnedOn?: string }) => void
}

/**
 * The guided cancel-invoice modal.
 *
 * The owner ruling, in one screen: **explicit, never silent — but never verbose.** The
 * user cancels an invoice and, on the SAME screen, says what is happening to the goods,
 * so they cannot inadvertently miss an inventory action they should be taking. Whatever
 * they choose — including "nothing comes back" — is recorded.
 *
 * ── THE MODAL ALWAYS RENDERS ──────────────────────────────────────────────────
 * Only the OPTION GROUP is conditional. `reason` is `required|max:500` on the server
 * and has no other UI, so a services-only invoice still needs this dialog.
 *
 * ── OPTION → MODE IS PER BRANCH, NOT PER OPTION ───────────────────────────────
 * This is the part that is easy to get wrong, because the radio's own name is NOT
 * always what gets posted:
 *
 * | Branch                                   | Option group | Option 3 posts    | Pre-selected |
 * |------------------------------------------|--------------|-------------------|--------------|
 * | `!requires_return_decision` (services)   | not rendered | `not_applicable`  | n/a, no click needed |
 * | `requires_return_decision && !goods_issued` | 1+2 DISABLED | `no_goods_issued` | option 3 |
 * | `goods_issued`                           | all enabled  | `no_return`       | none — submit blocked until chosen |
 *
 * The middle row exists because "the goods stayed out" and "the goods never left" are
 * different facts: recording the first when the second is true would tell an auditor the
 * customer kept units that never shipped.
 *
 * Options 1 and 2 are DISABLED WITH A REASON rather than hidden, so the user learns why
 * the choice is unavailable instead of wondering where it went. The disabling is
 * affordance only — the server refuses a goods-bearing mode independently
 * (`RETURN_NOTHING_DELIVERED`), because a stale client or a direct API call bypasses the
 * UI entirely.
 *
 * This is the ONLY confirmation step. No nested ConfirmDialog: the ruling authorises
 * confirmation "where it prevents misclicks", and a second dialog on top of a dialog
 * that already asks two deliberate questions is ceremony, not protection.
 */
export function CancelInvoiceModal({
  isOpen,
  onClose,
  invoiceNumber,
  canCancel,
  isSubmitting,
  errorCode,
  errorDetails,
  onSubmit,
}: CancelInvoiceModalProps) {
  const { t } = useTranslation(['sales', 'common'])

  const requiresDecision = canCancel?.requires_return_decision ?? false
  // FAIL CLOSED: while `can-cancel` is still loading, or if it could not be resolved,
  // treat the invoice as having issued no goods. The safe default is the one that
  // cannot restock inventory that never left.
  const goodsIssued = canCancel?.goods_issued ?? false

  const schema = useMemo(
    () =>
      z.object({
        reason: z
          .string()
          .min(1, t('sales:invoices.cancelFlow.reasonLabel'))
          .max(500),
        // Every zod message is a t() RESULT, never a raw key — the message is surfaced
        // verbatim through FormField's `error`.
        goodsOption: requiresDecision
          ? z.enum(['will_return', 'already_returned', 'no_return'], {
            message: t('sales:invoices.cancelFlow.chooseAnOption'),
          })
          : z.enum(['will_return', 'already_returned', 'no_return']).optional(),
        returnedOn: z.string().optional(),
      }),
    [requiresDecision, t],
  )

  type FormValues = z.infer<typeof schema>

  const {
    control,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema) as never,
    defaultValues: {
      reason: '',
      // Option 3 is PRE-SELECTED in the no-delivery branch: it is the only live choice
      // there, so making the user click it would be ceremony. In the goods_issued
      // branch nothing is pre-selected — the decision must be deliberate.
      ...(requiresDecision && !goodsIssued ? { goodsOption: 'no_return' as const } : {}),
      returnedOn: new Date().toISOString().slice(0, 10),
    },
  })

  const selectedOption = watch('goodsOption')

  /** The branch-dependent mapping from the clicked radio to the posted mode. */
  const resolveMode = (option: GoodsOption | undefined): ReturnDecisionMode => {
    if (!requiresDecision) return 'not_applicable'
    if (option === 'will_return') return 'will_return'
    if (option === 'already_returned') return 'already_returned'
    return goodsIssued ? 'no_return' : 'no_goods_issued'
  }

  const submit = (values: FormValues) => {
    const mode = resolveMode(values.goodsOption as GoodsOption | undefined)
    onSubmit({
      reason: values.reason,
      mode,
      // `returned_on` is PROHIBITED by the server for every other mode — sending it
      // would 422, and a date silently ignored would read as "I recorded that".
      ...(mode === 'already_returned' && values.returnedOn
        ? { returnedOn: values.returnedOn }
        : {}),
    })
  }

  const bannerMessage = useMemo(() => {
    if (!errorCode) return null

    // Period refusals render INLINE on the date field, not as a banner — the obstacle
    // is the date the user typed, and a banner would not tell them where to fix it.
    if (
      errorCode === CancelErrorCodes.RETURN_PERIOD_CLOSED ||
      errorCode === CancelErrorCodes.RETURN_PERIOD_FILED ||
      errorCode === CancelErrorCodes.RETURN_PERIOD_LOCKED
    ) {
      return null
    }

    if (
      errorCode === CancelErrorCodes.RETURN_EXCEEDS_DELIVERED_QUANTITY ||
      errorCode === CancelErrorCodes.RETURN_EXCEEDS_INVOICED_QUANTITY
    ) {
      // The refusal is PRODUCT-KEYED and, after CF-D7, there is no line UI at all — so
      // the banner has to name the product and what is left, or it is unactionable.
      const details = (errorDetails ?? {}) as { product_id?: string; remaining_returnable?: string }
      return t(`sales:invoices.cancelFlow.errors.${errorCode}`, {
        product: details.product_id ?? '',
        remaining: details.remaining_returnable ?? '',
      })
    }

    const known = Object.values(CancelErrorCodes) as string[]
    // An explicit translated fallback for "422 with no readable error.code" — without
    // it the user would see axios' bare "Request failed with status code 422".
    return known.includes(errorCode)
      ? t(`sales:invoices.cancelFlow.errors.${errorCode}`)
      : t('sales:invoices.cancelFlow.errors.unknown')
  }, [errorCode, errorDetails, t])

  const periodError = useMemo(() => {
    if (
      errorCode === CancelErrorCodes.RETURN_PERIOD_CLOSED ||
      errorCode === CancelErrorCodes.RETURN_PERIOD_FILED ||
      errorCode === CancelErrorCodes.RETURN_PERIOD_LOCKED
    ) {
      return t(`sales:invoices.cancelFlow.errors.${errorCode}`)
    }
    return undefined
  }, [errorCode, t])

  const optionsDisabled = requiresDecision && !goodsIssued

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('sales:invoices.cancelFlow.title', { number: invoiceNumber })}
    >
      <form onSubmit={(event) => { void handleSubmit(submit)(event) }} className="space-y-5">
        {bannerMessage !== null && (
          <div className={`flex gap-3 rounded-lg border ${semanticColorTokens.intent.danger.borderSubtle} ${semanticColorTokens.intent.danger.bgSubtle} p-3`}>
            <AlertCircle className={`h-5 w-5 flex-shrink-0 ${textColors.error}`} />
            <p className={`text-sm ${textColors.error}`} role="alert">{bannerMessage}</p>
          </div>
        )}

        <Controller
          name="reason"
          control={control}
          render={({ field }) => (
            <FormField
              label={t('sales:invoices.cancelFlow.reasonLabel')}
              required
              error={errors.reason?.message}
            >
              <Textarea
                {...field}
                rows={2}
                maxLength={500}
                disabled={isSubmitting}
                placeholder={t('sales:invoices.cancelFlow.reasonPlaceholder')}
              />
            </FormField>
          )}
        />

        {requiresDecision && (
          <fieldset className={`space-y-3 rounded-lg border ${borderColors.default} p-4`}>
            <legend className={`px-1 text-sm font-medium ${textColors.primary}`}>
              {t('sales:invoices.cancelFlow.goodsQuestion')}
            </legend>
            <p className={`text-sm ${textColors.secondary}`}>
              {t('sales:invoices.cancelFlow.goodsIntro')}
            </p>

            {optionsDisabled && (
              <p className={`text-sm ${textColors.secondary}`} role="note">
                {t('sales:invoices.cancelFlow.optionsDisabledReason')}
              </p>
            )}

            <Controller
              name="goodsOption"
              control={control}
              render={({ field }) => (
                <FormField error={errors.goodsOption?.message}>
                  <div className="space-y-3">
                    <label className="flex items-start gap-3">
                      <Radio
                        name="goodsOption"
                        value="will_return"
                        checked={field.value === 'will_return'}
                        onChange={() => { field.onChange('will_return') }}
                        disabled={isSubmitting || optionsDisabled}
                        className="mt-1"
                      />
                      <span>
                        <span className={`block text-sm font-medium ${textColors.primary}`}>
                          {t('sales:invoices.cancelFlow.option1.label')}
                        </span>
                        <span className={`block text-sm ${textColors.secondary}`}>
                          {t('sales:invoices.cancelFlow.option1.hint')}
                        </span>
                      </span>
                    </label>

                    <label className="flex items-start gap-3">
                      <Radio
                        name="goodsOption"
                        value="already_returned"
                        checked={field.value === 'already_returned'}
                        onChange={() => { field.onChange('already_returned') }}
                        disabled={isSubmitting || optionsDisabled}
                        className="mt-1"
                      />
                      <span>
                        <span className={`block text-sm font-medium ${textColors.primary}`}>
                          {t('sales:invoices.cancelFlow.option2.label')}
                        </span>
                        <span className={`block text-sm ${textColors.secondary}`}>
                          {t('sales:invoices.cancelFlow.option2.hint')}
                        </span>
                        {/*
                          * The scope and the finality, stated. Post-CF-D7 option 2 is a
                          * FULL-quantity, one-click, SEALED action — a user who got 3 of
                          * 5 units back has no in-modal escape, so they must be told one
                          * exists elsewhere.
                          */}
                        <span className={`mt-1 block text-sm ${textColors.secondary}`}>
                          {t('sales:invoices.cancelFlow.option2.scope')}
                        </span>
                        <span className={`block text-sm ${textColors.secondary}`}>
                          {t('sales:invoices.cancelFlow.option2.partialPointer')}
                        </span>
                      </span>
                    </label>

                    {selectedOption === 'already_returned' && (
                      <div className="ps-8">
                        <Controller
                          name="returnedOn"
                          control={control}
                          render={({ field: dateField }) => (
                            <FormField
                              label={t('sales:invoices.cancelFlow.option2.dateLabel')}
                              required
                              error={periodError ?? errors.returnedOn?.message}
                            >
                              <Input
                                {...dateField}
                                type="date"
                                max={new Date().toISOString().slice(0, 10)}
                                disabled={isSubmitting}
                              />
                            </FormField>
                          )}
                        />
                      </div>
                    )}

                    <label className="flex items-start gap-3">
                      <Radio
                        name="goodsOption"
                        value="no_return"
                        checked={field.value === 'no_return'}
                        onChange={() => { field.onChange('no_return') }}
                        disabled={isSubmitting}
                        className="mt-1"
                      />
                      <span>
                        <span className={`block text-sm font-medium ${textColors.primary}`}>
                          {t('sales:invoices.cancelFlow.option3.label')}
                        </span>
                        {/*
                          * Two branch-specific hints. Claiming "the goods stay out" when
                          * nothing ever shipped would be a false statement about physical
                          * reality, recorded on a fiscal document.
                          */}
                        <span className={`block text-sm ${textColors.secondary}`}>
                          {goodsIssued
                            ? t('sales:invoices.cancelFlow.option3.hint.goodsIssued')
                            : t('sales:invoices.cancelFlow.option3.hint.noGoodsIssued')}
                        </span>
                      </span>
                    </label>
                  </div>
                </FormField>
              )}
            />
          </fieldset>
        )}

        <div className={`flex justify-end gap-3 border-t ${borderColors.default} pt-4`}>
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting}>
            {t('sales:invoices.cancelFlow.keep')}
          </Button>
          <Button type="submit" variant="danger" disabled={isSubmitting}>
            {isSubmitting
              ? t('sales:invoices.cancelFlow.submitting')
              : t('sales:invoices.cancelFlow.submit')}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
