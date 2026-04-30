import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import type { VoucherSource, VoucherProvenance, RefundProvenance, GoodwillProvenance, LoyaltyCreditProvenance } from '../types/voucher'

interface ProvenanceSectionProps {
  voucherSource: VoucherSource
  provenance: VoucherProvenance
}

export function ProvenanceSection({ voucherSource, provenance }: ProvenanceSectionProps) {
  const { t } = useTranslation('vouchers')

  if (!provenance) return null

  if (voucherSource === 'Refund' || voucherSource === 'ExchangeSurplus') {
    const p = provenance as RefundProvenance
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          {p.source_receipt_number && (
            <>
              <dt className="text-gray-500">{t('provenance.sourceReceipt')}</dt>
              <dd>
                {p.source_receipt_id ? (
                  <Link
                    to={`/pos/receipts/${p.source_receipt_id}`}
                    className="text-blue-600 hover:underline"
                  >
                    {p.source_receipt_number}
                  </Link>
                ) : (
                  p.source_receipt_number
                )}
              </dd>
            </>
          )}
          {p.credit_note_link && (
            <>
              <dt className="text-gray-500">{t('provenance.creditNoteLink')}</dt>
              <dd>
                <Link to={p.credit_note_link} className="text-blue-600 hover:underline">
                  {p.credit_note_link}
                </Link>
              </dd>
            </>
          )}
        </dl>
      </div>
    )
  }

  if (voucherSource === 'Goodwill') {
    const p = provenance as GoodwillProvenance
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          {p.issued_by_user_name && (
            <>
              <dt className="text-gray-500">{t('provenance.issuedBy')}</dt>
              <dd className="text-gray-900">{p.issued_by_user_name}</dd>
            </>
          )}
          {p.notes && (
            <>
              <dt className="text-gray-500">{t('provenance.notes')}</dt>
              <dd className="text-gray-900">{p.notes}</dd>
            </>
          )}
          {p.override_reason && (
            <>
              <dt className="text-gray-500">{t('provenance.overrideReason')}</dt>
              <dd className="text-gray-900">{p.override_reason}</dd>
            </>
          )}
        </dl>
      </div>
    )
  }

  if (voucherSource === 'LoyaltyCredit') {
    const p = provenance as LoyaltyCreditProvenance
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        {p.source_loyalty_transaction_id ? (
          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt className="text-gray-500">{t('provenance.loyaltyTransactionId')}</dt>
            <dd className="text-gray-900 font-mono text-xs">{p.source_loyalty_transaction_id}</dd>
          </dl>
        ) : (
          <p className="text-sm text-gray-500 italic">{t('provenance.loyaltyPhase15Placeholder')}</p>
        )}
      </div>
    )
  }

  if (voucherSource === 'GiftCard' || voucherSource === 'Promotional') {
    return (
      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-gray-700">{t('provenance.title')}</h3>
        <p className="text-sm text-gray-500 italic">{t('provenance.phase2Placeholder')}</p>
      </div>
    )
  }

  return null
}
