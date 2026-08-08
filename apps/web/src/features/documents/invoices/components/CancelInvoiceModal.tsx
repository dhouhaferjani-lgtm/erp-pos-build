import { useEffect, useMemo, useRef } from 'react'
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

/**
 * Today's date in the BROWSER'S LOCAL timezone, as `YYYY-MM-DD`.
 *
 * Gate CF round 1, fiscal MINOR: `new Date().toISOString().slice(0, 10)` is a UTC date,
 * while the server validates `before_or_equal:today` in the APP timezone. For a TN
 * tenant (UTC+1) between 00:00 and 01:00 local it pre-filled and max-capped `returned_on`
 * at *yesterday*; east of UTC it can be tomorrow and 422 with no inline message.
 */
function todayLocalIsoDate(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

/** The radio the user clicks. What gets POSTED depends on the branch — see below. */
type GoodsOption = 'will_return' | 'already_returned' | 'no_return'

export interface CancelInvoiceModalProps {
  isOpen: boolean
  onClose: () => void
  invoiceNumber: string
  /**
   * The invoice's own `document_date`. The server enforces
   * `after_or_equal:<invoice document_date>` on `returned_on`, and without this the modal
   * could not express that half of the contract at all (gate CF round 2, NB4).
   */
  invoiceDocumentDate?: string | undefined
  canCancel: CanCancelResponse | undefined
  /**
   * FALSE while `/can-cancel` is still in flight or has failed. Gate CF round 1, B3:
   * the modal must not offer a submittable form until the server has told it what this
   * invoice actually is.
   */
  canCancelResolved: boolean
  /**
   * TRUE when `/can-cancel` FAILED. Distinct from "not resolved yet" on purpose (gate CF
   * round 2, NB1): the two need different copy and only one of them is recoverable by
   * waiting.
   */
  canCancelErrored?: boolean | undefined
  /** Re-runs `/can-cancel`. Without it an errored query is a dead end until page reload. */
  onRetryCanCancel?: (() => void) | undefined
  isSubmitting: boolean
  /** The typed refusal from the last attempt, if any. */
  errorCode?: string | undefined
  errorDetails?: Record<string, unknown> | undefined
  /**
   * TRUE once a submit has come back failed. Needed because a 422 can arrive with NO
   * readable `error.code` at all, and "no code" must still produce actionable feedback.
   */
  submitFailed?: boolean | undefined
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
  invoiceDocumentDate,
  canCancel,
  canCancelResolved,
  canCancelErrored,
  onRetryCanCancel,
  isSubmitting,
  errorCode,
  errorDetails,
  submitFailed,
  onSubmit,
}: CancelInvoiceModalProps) {
  const { t } = useTranslation(['sales', 'common'])

  /**
   * FAIL CLOSED IN BOTH DIRECTIONS (gate CF round 1, Blocker B3).
   *
   * `requires_return_decision` previously defaulted to FALSE, which fails OPEN — the
   * direction that SKIPS the goods question entirely and posts `not_applicable`. The
   * modal mounts the moment the invoice loads, while `/can-cancel` is still in flight,
   * and permanently if that endpoint errors, so a user clicking Cancel in that window got
   * a modal with no goods question and recorded "this invoice has no physical products"
   * against an invoice with delivered goods.
   *
   * Unknown now means "assume there IS a decision to make" (ask the question) and
   * "assume no goods issued" (do not offer a restock that could create inventory). Both
   * defaults point away from a silent, irreversible mistake. The server enforces the same
   * facts independently — `RETURN_DECISION_MISMATCHES_GOODS` and
   * `RETURN_NOTHING_DELIVERED` — because a UI default is affordance, not a safety
   * property.
   */
  const requiresDecision = canCancel?.requires_return_decision ?? true
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
        // A REAL constraint, not a bare optional string (gate CF round 1, m3). The field
        // renders `required` and the server enforces `required_if` +
        // `before_or_equal:today`; a zod schema that constrains nothing is also what fed
        // B2's silent path, since a client-side miss became a code-less 422.
        // The FULL server contract, with real error sentences (gate CF round 2, NB4 /
        // m3). Round 1 shipped the ceiling but not the floor, and used a FIELD LABEL
        // ("Date the goods came back") as the regex's message — which reads as a caption,
        // not as what went wrong. A client-side miss on the floor became a code-less 422,
        // which is also what fed B2's silent path.
        returnedOn: z
          .string()
          .regex(/^\d{4}-\d{2}-\d{2}$/, t('sales:invoices.cancelFlow.errors.invalidReturnDate'))
          .refine((value) => value <= todayLocalIsoDate(), {
            message: t('sales:invoices.cancelFlow.errors.futureReturnDate'),
          })
          .refine((value) => invoiceDocumentDate === undefined || value >= invoiceDocumentDate, {
            message: t('sales:invoices.cancelFlow.errors.returnDateBeforeInvoice'),
          })
          .optional(),
      }),
    [requiresDecision, t],
  )

  type FormValues = z.infer<typeof schema>

  const {
    control,
    handleSubmit,
    watch,
    reset,
    setValue,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema) as never,
    defaultValues: {
      reason: '',
      // Option 3 is PRE-SELECTED in the no-delivery branch: it is the only live choice
      // there, so making the user click it would be ceremony. In the goods_issued
      // branch nothing is pre-selected — the decision must be deliberate.
      ...(requiresDecision && !goodsIssued ? { goodsOption: 'no_return' as const } : {}),
      returnedOn: todayLocalIsoDate(),
    },
  })

  /**
   * Two DIFFERENT triggers, deliberately kept apart (gate CF round 3, NB-2 / R2).
   *
   * `Modal` returns null when closed but the component stays MOUNTED, and RHF captures
   * `defaultValues` only once — so without a reset, plan T10's binding option→mode row
   * ("option 3 **pre-selected**" in the `!goods_issued` branch) never held in the running
   * app, and stale state survived close→reopen. Round 1 fixed that with a blind reset;
   * round 2 then discovered the blind reset WIPED the reason a user typed while
   * `/can-cancel` was still in flight (B3's own fix makes that window visible), and made
   * the reset preserve `reason`/`returnedOn` — which brought the stale-state defect back
   * and, worse, carried it ACROSS INVOICES, because the route renders
   * `<InvoiceDetailPage />` without a `key`, so navigating A → B reuses this form.
   * `returned_on` drives the return note's `document_date` and therefore which fiscal
   * period the restock lands in, so a value pre-filled from another invoice is not
   * cosmetic.
   *
   * The two cases need opposite answers, so they get separate effects:
   *
   *  1. **OPEN transition (closed → open), or a different invoice.** Everything is
   *     cleared. Nothing the user typed for a previous attempt — or a previous invoice —
   *     may pre-fill this one.
   *  2. **Branch resolution while already open.** `/can-cancel` landing must NOT touch
   *     what the user has typed; only the branch-dependent `goodsOption` is re-derived.
   */
  const wasOpen = useRef(false)

  useEffect(() => {
    const justOpened = isOpen && !wasOpen.current
    wasOpen.current = isOpen

    if (!justOpened) return

    reset({
      reason: '',
      ...(requiresDecision && !goodsIssued ? { goodsOption: 'no_return' as const } : {}),
      returnedOn: todayLocalIsoDate(),
    })
    // Only the open transition may clear the form; the branch values are read at that
    // moment and deliberately not tracked here (case 2 owns them).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, invoiceNumber])

  useEffect(() => {
    if (!isOpen || !wasOpen.current) return

    // Case 2: re-derive ONLY the branch-dependent option. `reason` and `returnedOn` are
    // whatever the user has in front of them.
    setValue(
      'goodsOption',
      requiresDecision && !goodsIssued ? 'no_return' : undefined,
      { shouldValidate: false, shouldDirty: false },
    )
  }, [isOpen, requiresDecision, goodsIssued, setValue])

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
    if (!errorCode) {
      // Gate CF round 1, Blocker B2. A 422 can arrive with NO readable `error.code` at
      // all — a Laravel `ValidationException` (`{message, errors}`) or the surviving
      // generic flat envelope `{error: <string>, code: <string>}` that T6 deliberately
      // left in place. `extractErrorCode` reads `error.code` and yields undefined for
      // both, so the banner was suppressed, no inline error rendered and no toast fired:
      // the user clicked Cancel and NOTHING happened. Plan T10 mandates the opposite
      // verbatim — "an explicit translated fallback for '422 with no readable
      // error.code'" — and the key existed but was reachable only for a PRESENT but
      // unrecognised code.
      return submitFailed === true ? t('sales:invoices.cancelFlow.errors.unknown') : null
    }

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
  }, [errorCode, errorDetails, submitFailed, t])

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
                {/*
                  * While `/can-cancel` is unresolved or errored the delivery state is
                  * UNKNOWN, so the modal must not state "no confirmed delivery note is
                  * linked to this invoice" as fact (gate CF round 2, NB1). The
                  * fail-closed default disables options 1 and 2 either way; only the
                  * REASON differs, and asserting a falsehood about physical reality is
                  * precisely what this lane exists to stop.
                  */}
                {canCancelResolved
                  ? t('sales:invoices.cancelFlow.optionsDisabledReason')
                  : t('sales:invoices.cancelFlow.optionsUnknownReason')}
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
                          {t('sales:invoices.cancelFlow.option1.hint', {
                            // m7: the state word comes from the canonical return-note
                            // status key, not a hardcoded "draft"/"brouillon".
                            status: t('sales:returnNotes.status.draft'),
                          })}
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
                          {t('sales:invoices.cancelFlow.option2.scope', {
                            status: t('sales:returnNotes.status.draft'),
                          })}
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
                                max={todayLocalIsoDate()}
                                {...(invoiceDocumentDate !== undefined ? { min: invoiceDocumentDate } : {})}
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
                          {/*
                            * Gate CF round 3, R1. NB1 moved the falsehood out of
                            * `optionsDisabledReason`, but this hint carries the same class
                            * of claim one element lower: while `/can-cancel` is unresolved
                            * or errored, `goodsIssued` defaults to false and this rendered
                            * "Nothing was ever delivered against this invoice" as FACT —
                            * directly underneath a banner saying we could not check the
                            * invoice. Submit is blocked either way, so nothing wrong could
                            * be posted; the defect is that the modal asserted something it
                            * did not know.
                            */}
                          {!canCancelResolved
                            ? t('sales:invoices.cancelFlow.option3.hint.unknown')
                            : goodsIssued
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

        {!canCancelResolved && !canCancelErrored && (
          <p className={`text-sm ${textColors.secondary}`} role="status">
            {t('sales:invoices.cancelFlow.loading')}
          </p>
        )}

        {/*
          * Gate CF round 2, NB1. `checkCancellable()` 422s on any exception and TanStack
          * does not retry past its default budget, so an errored query left the user with
          * a live Cancel button, a modal that said it was still *checking*, a permanently
          * disabled submit, and NO error, NO retry and no explanation until a page
          * reload. The round-1 directive asked for a loading state AND a translated
          * failure state; only the loading half shipped.
          */}
        {canCancelErrored && (
          <div
            className={`flex items-start justify-between gap-3 rounded-lg border ${semanticColorTokens.intent.danger.borderSubtle} ${semanticColorTokens.intent.danger.bgSubtle} p-3`}
            role="alert"
          >
            <p className={`text-sm ${textColors.error}`}>
              {t('sales:invoices.cancelFlow.checkFailed')}
            </p>
            {onRetryCanCancel !== undefined && (
              <Button type="button" variant="secondary" onClick={onRetryCanCancel} disabled={isSubmitting}>
                {t('sales:invoices.cancelFlow.retryCheck')}
              </Button>
            )}
          </div>
        )}

        <div className={`flex justify-end gap-3 border-t ${borderColors.default} pt-4`}>
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting}>
            {t('sales:invoices.cancelFlow.keep')}
          </Button>
          {/*
            * Submit stays disabled until `/can-cancel` has answered (B3). Everything the
            * modal decides — whether to ask the goods question at all, which options are
            * live, which mode option 3 posts — depends on that answer, so submitting
            * before it lands is submitting a guess about physical reality.
            */}
          <Button type="submit" variant="danger" disabled={isSubmitting || !canCancelResolved}>
            {isSubmitting
              ? t('sales:invoices.cancelFlow.submitting')
              : t('sales:invoices.cancelFlow.submit')}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
