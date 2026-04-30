import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import type { VoucherProvenance } from '../types/voucher'

interface ProvenanceSectionProps {
  provenance: VoucherProvenance
}

export function ProvenanceSection({ provenance }: ProvenanceSectionProps) {
  const { t } = useTranslation('vouchers')

  if (!provenance) return null

  if (provenance.source === 'Refund' || provenance.source === 'ExchangeSurplus') {
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          {provenance.source_receipt_number && (
            <>
              <dt className="text-gray-500">{t('provenance.sourceReceipt')}</dt>
              <dd>
                {provenance.source_receipt_id ? (
                  <Link
                    to={`/pos/receipts/${provenance.source_receipt_id}`}
                    className="text-blue-600 hover:underline"
                  >
                    {provenance.source_receipt_number}
                  </Link>
                ) : (
                  provenance.source_receipt_number
                )}
              </dd>
            </>
          )}
          {provenance.credit_note_link && (
            <>
              <dt className="text-gray-500">{t('provenance.creditNoteLink')}</dt>
              <dd>
                <Link to={provenance.credit_note_link} className="text-blue-600 hover:underline">
                  {provenance.credit_note_link}
                </Link>
              </dd>
            </>
          )}
        </dl>
      </div>
    )
  }

  if (provenance.source === 'Goodwill') {
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          {provenance.issued_by_user_name && (
            <>
              <dt className="text-gray-500">{t('provenance.issuedBy')}</dt>
              <dd className="text-gray-900">{provenance.issued_by_user_name}</dd>
            </>
          )}
          {provenance.notes && (
            <>
              <dt className="text-gray-500">{t('provenance.notes')}</dt>
              <dd className="text-gray-900">{provenance.notes}</dd>
            </>
          )}
          {provenance.override_reason && (
            <>
              <dt className="text-gray-500">{t('provenance.overrideReason')}</dt>
              <dd className="text-gray-900">{provenance.override_reason}</dd>
            </>
          )}
        </dl>
      </div>
    )
  }

  if (provenance.source === 'LoyaltyCredit') {
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        {provenance.loyalty_transaction_id ? (
          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt className="text-gray-500">{t('provenance.loyaltyTransactionId')}</dt>
            <dd className="text-gray-900 font-mono text-xs">{provenance.loyalty_transaction_id}</dd>
          </dl>
        ) : (
          <p className="text-sm text-gray-500 italic">{t('provenance.loyaltyPhase15Placeholder')}</p>
        )}
      </div>
    )
  }

  if (provenance.source === 'GiftCard' || provenance.source === 'Promotional') {
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        <p className="text-sm text-gray-500 italic">{t('provenance.phase2Placeholder')}</p>
      </div>
    )
  }

  return null
}
