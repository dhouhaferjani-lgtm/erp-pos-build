import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Ban, CalendarClock, ArrowLeftRight } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { tokens, colors, textColors, borderColors } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { useVoucher } from '../hooks/useVouchers'
import { useVoidVoucher, useExtendExpiry, useTransferVoucher } from '../hooks/useVoucherMutations'
import { SourceBadge } from '../components/SourceBadge'
import { StatusBadge } from '../components/StatusBadge'
import { ProvenanceSection } from '../components/ProvenanceSection'
import { LedgerHistoryTable } from '../components/LedgerHistoryTable'
import { VoidVoucherModal } from '../components/VoidVoucherModal'
import { ExtendExpiryModal } from '../components/ExtendExpiryModal'
import { TransferVoucherModal } from '../components/TransferVoucherModal'
import type { VoidVoucherPayload, ExtendExpiryPayload, TransferVoucherPayload } from '../types/voucher'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function VoucherDetailPage() {
  const { t } = useTranslation(['vouchers', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const { hasPermission } = usePermissions()

  const { data: voucher, isLoading, isError } = useVoucher(id ?? '')

  const voidMutation = useVoidVoucher()
  const extendMutation = useExtendExpiry()
  const transferMutation = useTransferVoucher()

  const [isVoidOpen, setIsVoidOpen] = useState(false)
  const [isExtendOpen, setIsExtendOpen] = useState(false)
  const [isTransferOpen, setIsTransferOpen] = useState(false)

  const canVoid = hasPermission('pos.void_voucher')
  const canExtend = hasPermission('pos.extend_voucher_expiry')
  const canTransfer = hasPermission('pos.transfer_voucher')

  const handleVoidSubmit = (payload: VoidVoucherPayload) => {
    if (!voucher) return
    voidMutation.mutate(
      { id: voucher.id, payload },
      { onSuccess: () => { setIsVoidOpen(false) } },
    )
  }

  const handleExtendSubmit = (payload: ExtendExpiryPayload) => {
    if (!voucher) return
    extendMutation.mutate(
      { id: voucher.id, payload },
      { onSuccess: () => { setIsExtendOpen(false) } },
    )
  }

  const handleTransferSubmit = (payload: TransferVoucherPayload) => {
    if (!voucher) return
    transferMutation.mutate(
      { id: voucher.id, payload },
      { onSuccess: () => { setIsTransferOpen(false) } },
    )
  }

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    )
  }

  if (isError || !voucher) {
    return (
      <div className="text-center py-12">
        <p className={textColors.tertiary}>{t('vouchers:errors.notFound')}</p>
        <Button
          variant="secondary"
          className="mt-4"
          onClick={() => { navigate('/pos/vouchers') }}
        >
          {t('vouchers:detail.backToList')}
        </Button>
      </div>
    )
  }

  const isTerminal = voucher.status === 'Voided' || voucher.status === 'FullyRedeemed'

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button
            type="button"
            onClick={() => { navigate('/pos/vouchers') }}
            className={`p-2 rounded-lg ${colors.hover.gray100}`}
            aria-label={t('vouchers:detail.backToList')}
          >
            <ArrowLeft className="w-5 h-5" />
          </button>
          <div>
            <div className="flex items-center gap-3">
              <PageHeaderTitle className={`text-2xl font-bold font-mono ${textColors.primary}`}>{voucher.code}</PageHeaderTitle>
              <StatusBadge status={voucher.status} />
              <SourceBadge source={voucher.source} />
            </div>
            <p className={`text-sm ${textColors.tertiary} mt-1`}>{t('vouchers:detail.headerSubtitle')}</p>
          </div>
        </div>

        {/* Action buttons */}
        <div className="flex gap-2">
          {canTransfer && (
            <Button
              variant="secondary"
              onClick={() => { setIsTransferOpen(true) }}
            >
              <ArrowLeftRight className="w-4 h-4 mr-2" />
              {t('vouchers:actions.transfer')}
            </Button>
          )}
          {canExtend && !isTerminal && (
            <Button
              variant="secondary"
              onClick={() => { setIsExtendOpen(true) }}
            >
              <CalendarClock className="w-4 h-4 mr-2" />
              {t('vouchers:actions.extend')}
            </Button>
          )}
          {canVoid && !isTerminal && (
            <Button
              variant="danger"
              onClick={() => { setIsVoidOpen(true) }}
            >
              <Ban className="w-4 h-4 mr-2" />
              {t('vouchers:actions.void')}
            </Button>
          )}
        </div>
      </div>

      {/* Summary card */}
      <div className={tokens.card.base}>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
          <div>
            <p className={`text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
              {t('vouchers:fields.currentBalance')}
            </p>
            <p className={`mt-1 text-2xl font-bold font-mono ${textColors.primary}`}>
              {voucher.current_balance}{' '}
              <span className={`text-base font-normal ${textColors.tertiary}`}>{voucher.currency}</span>
            </p>
          </div>
          <div>
            <p className={`text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
              {t('vouchers:fields.initialBalance')}
            </p>
            <p className={`mt-1 text-lg font-mono ${textColors.secondary}`}>
              {voucher.initial_balance} {voucher.currency}
            </p>
          </div>
          <div>
            <p className={`text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
              {t('vouchers:fields.expiresAt')}
            </p>
            <p className={`mt-1 text-sm ${textColors.secondary}`}>
              {voucher.expires_at
                ? new Date(voucher.expires_at).toLocaleDateString()
                : '—'}
            </p>
          </div>
          <div>
            <p className={`text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
              {t('vouchers:fields.customer')}
            </p>
            <p className={`mt-1 text-sm ${textColors.secondary}`}>{voucher.partner_name ?? '—'}</p>
          </div>
        </div>
      </div>

      {/* Provenance */}
      <div className={`rounded-lg border ${borderColors.light} bg-white p-6 shadow-sm`}>
        <ProvenanceSection voucherSource={voucher.source} provenance={voucher.provenance} />
      </div>

      {/* Ledger history */}
      <div>
        <h2 className={`text-lg font-semibold ${textColors.primary} mb-3`}>{t('vouchers:ledger.title')}</h2>
        {voucher.ledger.length === 0 ? (
          <p className={`text-sm ${textColors.tertiary}`}>{t('vouchers:ledger.empty')}</p>
        ) : (
          <LedgerHistoryTable rows={voucher.ledger} currency={voucher.currency} />
        )}
      </div>

      {/* Modals */}
      <VoidVoucherModal
        isOpen={isVoidOpen}
        onClose={() => { setIsVoidOpen(false) }}
        onSubmit={handleVoidSubmit}
        isPending={voidMutation.isPending}
      />

      <ExtendExpiryModal
        isOpen={isExtendOpen}
        onClose={() => { setIsExtendOpen(false) }}
        onSubmit={handleExtendSubmit}
        isPending={extendMutation.isPending}
      />

      <TransferVoucherModal
        isOpen={isTransferOpen}
        onClose={() => { setIsTransferOpen(false) }}
        onSubmit={handleTransferSubmit}
        isPending={transferMutation.isPending}
      />
    </div>
  )
}
