import { AlertTriangle, Package, CheckCircle, Truck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../../../components/organisms/Modal/Modal'
import { useCompanyStore } from '../../../stores/companyStore'
import { formatCurrency } from '../../../lib/format'
import { colorClasses } from '@/lib/designTokens'

interface DraftDeliveryNote {
  id: string
  number: string
  total: string
  line_count: number
}

/**
 * Which of the two delivery situations this modal is rendering.
 *
 * - `confirm-existing`: delivery notes already exist in Draft and only need
 *   confirming (the sales-order conversion flow).
 * - `create-new`: nothing has been delivered at all and the country's
 *   pre-delivery invoicing policy refuses the posting. One will be CREATED from
 *   the invoice's own goods lines, then confirmed (DPA Wave 3 T25c / D-30).
 *
 * They are one component because they are one decision point for the user —
 * "these goods have not left yet" — and because two components drift into two
 * different accounts of what confirming does to stock and to the fiscal chain.
 */
export type DeliveryConfirmationVariant = 'confirm-existing' | 'create-new'

export type PreDeliveryPolicySource = 'company' | 'country' | 'system'

interface DeliveryConfirmationModalProps {
  isOpen: boolean
  onClose: () => void
  draftDeliveryNotes: DraftDeliveryNote[]
  onConfirmAndPost: () => void
  isLoading?: boolean
  variant?: DeliveryConfirmationVariant
  /** Where the resolved policy came from — shown so the user can tell a company override from a statutory default. */
  policySource?: PreDeliveryPolicySource
  /**
   * Set when the guided path CANNOT run (e.g. no resolvable stock location).
   * The modal then explains the manual route instead of offering a button that
   * would fail.
   */
  blockedReason?: string | null
}

/**
 * Modal for getting goods out of the door before an invoice is posted.
 *
 * Posting an invoice is an irreversible fiscal act — it seals the document into
 * the hash chain — so both variants spell out exactly what will move (stock) and
 * what will be sealed (the delivery note, then the invoice) before the user
 * commits.
 */
export function DeliveryConfirmationModal({
  isOpen,
  onClose,
  draftDeliveryNotes,
  onConfirmAndPost,
  isLoading = false,
  variant = 'confirm-existing',
  policySource = 'country',
  blockedReason = null,
}: DeliveryConfirmationModalProps) {
  const { t } = useTranslation(['sales', 'common'])
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const formatAmount = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return formatCurrency(isNaN(num) ? 0 : num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  const isCreateNew = variant === 'create-new'
  const ns = isCreateNew ? 'preDeliveryInvoicing' : 'deliveryConfirmation'
  const key = (name: string) => `sales:invoices.${ns}.${name}`

  const policySourceLabel = t(
    `sales:invoices.preDeliveryInvoicing.policySource${
      policySource.charAt(0).toUpperCase() + policySource.slice(1)
    }`,
  )

  const blockedCopy = (): string => {
    switch (blockedReason) {
      case 'NO_RESOLVABLE_LOCATION':
        return t('sales:invoices.preDeliveryInvoicing.blockedNoLocation')
      case 'NO_PHYSICAL_LINES':
        return t('sales:invoices.preDeliveryInvoicing.blockedNoPhysicalLines')
      default:
        return t('sales:invoices.preDeliveryInvoicing.blockedGeneric')
    }
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <ModalHeader title={t(key('title'))} onClose={onClose} />

      <ModalContent>
        {/* Warning Banner */}
        <div className={`rounded-lg border ${colorClasses.borderAmber200} ${colorClasses.bgAmber50} p-4`}>
          <div className="flex gap-3">
            <AlertTriangle className={`h-5 w-5 flex-shrink-0 ${colorClasses.textAmber600}`} />
            <div className="space-y-1">
              <p className={`text-sm font-medium ${colorClasses.textAmber900}`}>{t(key('warning'))}</p>
              <p className={`text-sm ${colorClasses.textAmber700}`}>{t(key('warningDetails'))}</p>
              {isCreateNew && (
                <p className={`text-sm ${colorClasses.textAmber700}`}>
                  {t('sales:invoices.preDeliveryInvoicing.policyInForce', { source: policySourceLabel })}
                </p>
              )}
            </div>
          </div>
        </div>

        {/* Description / delivery-note list — only the confirm-existing variant
            has notes to show; the create-new variant has none by construction. */}
        {!isCreateNew && (
          <>
            <div className={`text-sm ${colorClasses.textGray600}`}>
              <p>{t('sales:invoices.deliveryConfirmation.description')}</p>
            </div>

            <div className="space-y-3">
              <h3 className={`text-sm font-medium ${colorClasses.textGray900}`}>
                {t('sales:invoices.deliveryConfirmation.deliveryNotesTitle')}
              </h3>

              <div className={`divide-y ${colorClasses.divideGray200} rounded-lg border ${colorClasses.borderGray200}`}>
                {draftDeliveryNotes.map((dn) => (
                  <div key={dn.id} className="flex items-center justify-between p-4">
                    <div className="flex items-center gap-3">
                      <div className={`rounded-lg ${colorClasses.bgBlue100} p-2`}>
                        <Package className={`h-5 w-5 ${colorClasses.textBlue600}`} />
                      </div>
                      <div>
                        <p className={`font-medium ${colorClasses.textGray900}`}>{dn.number}</p>
                        <p className={`text-sm ${colorClasses.textGray500}`}>
                          {t('sales:invoices.deliveryConfirmation.lineCount', { count: dn.line_count })}
                        </p>
                      </div>
                    </div>
                    <div className="text-end">
                      <p className={`font-medium ${colorClasses.textGray900}`}>{formatAmount(dn.total)}</p>
                      <p className={`text-sm ${colorClasses.textGray500}`}>
                        {t('sales:invoices.deliveryConfirmation.statusDraft')}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </>
        )}

        {/* What Will Happen — hidden when the guided path cannot run, because
            promising these steps and then failing is worse than not offering. */}
        {!(isCreateNew && blockedReason !== null) && (
          <div className={`rounded-lg ${colorClasses.bgBlue50} p-4`}>
            <div className="flex gap-3">
              <CheckCircle className={`h-5 w-5 flex-shrink-0 ${colorClasses.textBlue600}`} />
              <div className="space-y-2">
                <p className={`text-sm font-medium ${colorClasses.textBlue900}`}>{t(key('whatHappensTitle'))}</p>
                <ul className={`space-y-1 text-sm ${colorClasses.textBlue700}`}>
                  <li>• {t(key('step1'))}</li>
                  <li>• {t(key('step2'))}</li>
                  <li>• {t(key('step3'))}</li>
                  <li>• {t(key('step4'))}</li>
                </ul>
              </div>
            </div>
          </div>
        )}

        {isCreateNew && blockedReason !== null && (
          <div className={`rounded-lg border ${colorClasses.borderGray200} p-4`}>
            <p className={`text-sm font-medium ${colorClasses.textGray900}`}>
              {t('sales:invoices.preDeliveryInvoicing.blockedTitle')}
            </p>
            <p className={`text-sm ${colorClasses.textGray600}`}>{blockedCopy()}</p>
          </div>
        )}

        {/* The alternative, always visible on the compliance variant: the goods
            genuinely may not have shipped, and the user needs somewhere to go
            that is not "post it anyway". */}
        {isCreateNew && (
          <div className={`rounded-lg border ${colorClasses.borderGray200} p-4`}>
            <div className="flex gap-3">
              <Truck className={`h-5 w-5 flex-shrink-0 ${colorClasses.textGray600}`} />
              <div className="space-y-1">
                <p className={`text-sm font-medium ${colorClasses.textGray900}`}>
                  {t('sales:invoices.preDeliveryInvoicing.alternativesTitle')}
                </p>
                <p className={`text-sm ${colorClasses.textGray600}`}>
                  {t('sales:invoices.preDeliveryInvoicing.alternativeOrder')}
                </p>
              </div>
            </div>
          </div>
        )}
      </ModalContent>

      <ModalFooter>
        <button
          type="button"
          onClick={onClose}
          disabled={isLoading}
          className={`rounded-lg border ${colorClasses.borderGray300} bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50`}
        >
          {t('common:cancel')}
        </button>
        {/* 🚫 There is deliberately NO "post as invoice only" affordance. Under
            `require_delivery_first` that outcome does not exist, and offering a
            disabled version of it teaches the user to look for a way around. */}
        {!(isCreateNew && blockedReason !== null) && (
          <button
            type="button"
            onClick={onConfirmAndPost}
            disabled={isLoading}
            className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700} disabled:opacity-50`}
          >
            {isLoading ? (
              <>
                <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                {t(key(isCreateNew ? 'creating' : 'confirming'))}
              </>
            ) : (
              <>
                <CheckCircle className="h-4 w-4" />
                {t(key(isCreateNew ? 'createAndPost' : 'confirmAndPost'))}
              </>
            )}
          </button>
        )}
      </ModalFooter>
    </Modal>
  )
}
