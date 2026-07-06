import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Pencil, Plus, MinusCircle, RefreshCw, Coins, ChevronDown, ChevronUp } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Badge } from '@/components/atoms/Badge/Badge'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'

import {
  useMember,
  useEnrollments,
  useEnrollMember,
  useOptOutEnrollment,
  useReactivateEnrollment,
  useAdjustPoints,
  useTransactions,
} from '../hooks/useMembers'
import { MemberStatusBadge } from '../components/MemberStatusBadge'
import { EnrollMemberModal } from '../components/EnrollMemberModal'
import { AdjustPointsModal } from '../components/AdjustPointsModal'
import type { Enrollment, EnrollmentStatus, AdjustPointsData, TransactionType } from '../types/loyalty'

const ENROLLMENT_STATUS_VARIANTS: Record<EnrollmentStatus, 'success' | 'default' | 'warning'> = {
  active: 'success',
  suspended: 'default',
  opted_out: 'warning',
}

const TRANSACTION_TYPE_VARIANTS: Record<TransactionType, 'success' | 'warning' | 'info' | 'danger'> = {
  earn: 'success',
  redeem: 'warning',
  adjust: 'info',
  expire: 'danger',
  transfer_in: 'success',
  transfer_out: 'warning',
}

function TransactionHistorySection({ memberId, enrollment }: { memberId: string; enrollment: Enrollment }) {
  const { t } = useTranslation(['loyalty'])
  const [page, setPage] = useState(1)
  const { data, isLoading } = useTransactions(memberId, enrollment.id, page)

  const transactions = data?.data ?? []
  const meta = data?.meta

  if (isLoading) {
    return (
      <div className="flex justify-center py-4">
        <Spinner />
      </div>
    )
  }

  if (transactions.length === 0) {
    return <p className="text-sm text-gray-500 py-3">{t('loyalty:members.noTransactions')}</p>
  }

  return (
    <div className="mt-3">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase">
            <th className="py-2 pr-4">{t('loyalty:transactions.date')}</th>
            <th className="py-2 pr-4">{t('loyalty:transactions.type')}</th>
            <th className="py-2 pr-4 text-right">{t('loyalty:transactions.points')}</th>
            <th className="py-2 pr-4 text-right">{t('loyalty:transactions.balanceAfter')}</th>
            <th className="py-2">{t('loyalty:transactions.description')}</th>
          </tr>
        </thead>
        <tbody>
          {transactions.map((tx) => (
            <tr key={tx.id} className="border-b border-gray-100">
              <td className="py-2 pr-4 text-gray-600">
                {new Date(tx.created_at).toLocaleDateString()}
              </td>
              <td className="py-2 pr-4">
                <Badge variant={TRANSACTION_TYPE_VARIANTS[tx.transaction_type]}>
                  {t(`loyalty:transactionTypes.${tx.transaction_type}`)}
                </Badge>
              </td>
              <td className="py-2 pr-4 text-right font-medium">
                <span className={parseFloat(tx.amount) >= 0 ? 'text-green-600' : 'text-red-600'}>
                  {parseFloat(tx.amount) >= 0 ? '+' : ''}{tx.amount}
                </span>
              </td>
              <td className="py-2 pr-4 text-right text-gray-700">{tx.balance_after}</td>
              <td className="py-2 text-gray-600 truncate max-w-[200px]">{tx.description ?? '-'}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
          <span className="text-xs text-gray-500">
            {t('common:page')} {meta.current_page} / {meta.last_page}
          </span>
          <div className="flex gap-2">
            <Button
              variant="secondary"
              size="sm"
              onClick={() => { setPage((p) => Math.max(1, p - 1)); }}
              disabled={page <= 1}
            >
              {t('common:previous')}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              onClick={() => { setPage((p) => p + 1); }}
              disabled={page >= meta.last_page}
            >
              {t('common:next')}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

export function MemberDetailPage() {
  const { t } = useTranslation(['loyalty', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const { data: member, isLoading } = useMember(id ?? '')
  const { data: enrollments } = useEnrollments(id ?? '')
  const enrollMutation = useEnrollMember()
  const optOutMutation = useOptOutEnrollment()
  const reactivateMutation = useReactivateEnrollment()
  const adjustMutation = useAdjustPoints()

  const [isEnrollModalOpen, setIsEnrollModalOpen] = useState(false)
  const [adjustTarget, setAdjustTarget] = useState<Enrollment | null>(null)
  const [optOutTarget, setOptOutTarget] = useState<Enrollment | null>(null)
  const [expandedEnrollments, setExpandedEnrollments] = useState<Set<string>>(new Set())

  const toggleTransactions = (enrollmentId: string) => {
    setExpandedEnrollments((prev) => {
      const next = new Set(prev)
      if (next.has(enrollmentId)) {
        next.delete(enrollmentId)
      } else {
        next.add(enrollmentId)
      }
      return next
    })
  }

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    )
  }

  if (!member) {
    return <div className="text-center py-12 text-gray-500">{t('common:notFound')}</div>
  }

  const handleEnroll = (programId: string) => {
    enrollMutation.mutate(
      { memberId: member.id, programId },
      { onSuccess: () => { setIsEnrollModalOpen(false); } },
    )
  }

  const handleAdjust = (data: AdjustPointsData) => {
    if (adjustTarget) {
      adjustMutation.mutate(
        { memberId: member.id, enrollmentId: adjustTarget.id, data },
        { onSuccess: () => { setAdjustTarget(null); } },
      )
    }
  }

  const enrollmentsList = enrollments ?? []

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button
            type="button"
            onClick={() => navigate('/pos/loyalty/members')}
            className="p-2 rounded-lg hover:bg-gray-100"
          >
            <ArrowLeft className="w-5 h-5" />
          </button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">
                {[member.first_name, member.last_name].filter(Boolean).join(' ') || member.phone}
              </h1>
              <MemberStatusBadge status={member.status} />
            </div>
            <p className="text-sm text-gray-500 mt-1">{member.phone}</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => { setIsEnrollModalOpen(true); }}>
            <Plus className="w-4 h-4 mr-2" />
            {t('loyalty:actions.enroll')}
          </Button>
          <Button variant="secondary" onClick={() => navigate(`/pos/loyalty/members/${id}/edit`)}>
            <Pencil className="w-4 h-4 mr-2" />
            {t('loyalty:members.edit')}
          </Button>
        </div>
      </div>

      {/* Profile card */}
      <div className="bg-white rounded-lg border border-gray-200 p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('loyalty:members.profile')}</h2>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.phone')}</p>
            <p className="mt-1 text-sm text-gray-900">{member.phone}</p>
          </div>
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.email')}</p>
            <p className="mt-1 text-sm text-gray-900">{member.email ?? '-'}</p>
          </div>
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.dateOfBirth')}</p>
            <p className="mt-1 text-sm text-gray-900">
              {member.date_of_birth ? new Date(member.date_of_birth).toLocaleDateString() : '-'}
            </p>
          </div>
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.enrolledAt')}</p>
            <p className="mt-1 text-sm text-gray-900">
              {new Date(member.enrollment_date).toLocaleDateString()}
            </p>
          </div>
        </div>
      </div>

      {/* Enrollments */}
      <div>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('loyalty:members.enrollments')}</h2>
        {enrollmentsList.length === 0 ? (
          <div className="text-center py-8 bg-white rounded-lg border border-gray-200">
            <p className="text-gray-500">{t('loyalty:members.noEnrollments')}</p>
          </div>
        ) : (
          <div className="space-y-4">
            {enrollmentsList.map((enrollment) => (
              <div key={enrollment.id} className="bg-white rounded-lg border border-gray-200 p-5">
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-3">
                    <h3 className={`font-semibold ${textColors.primary}`}>
                      {enrollment.program?.name ?? enrollment.program_name ?? enrollment.program_id}
                    </h3>
                    <Badge variant={ENROLLMENT_STATUS_VARIANTS[enrollment.status]}>
                      {t(`loyalty:statuses.${enrollment.status}`)}
                    </Badge>
                  </div>
                  <div className="flex gap-1">
                    <button
                      onClick={() => { setAdjustTarget(enrollment); }}
                      className="p-1.5 rounded hover:bg-gray-100 text-gray-600"
                      title={t('loyalty:actions.adjustPoints')}
                    >
                      <Coins className="w-4 h-4" />
                    </button>
                    {enrollment.status === 'active' ? (
                      <button
                        onClick={() => { setOptOutTarget(enrollment); }}
                        className="p-1.5 rounded hover:bg-orange-50 text-orange-600"
                        title={t('loyalty:actions.optOut')}
                      >
                        <MinusCircle className="w-4 h-4" />
                      </button>
                    ) : enrollment.status === 'opted_out' ? (
                      <button
                        onClick={() =>
                          { reactivateMutation.mutate({
                            memberId: member.id,
                            enrollmentId: enrollment.id,
                          }); }
                        }
                        className="p-1.5 rounded hover:bg-green-50 text-green-600"
                        title={t('loyalty:actions.reactivate')}
                      >
                        <RefreshCw className="w-4 h-4" />
                      </button>
                    ) : null}
                  </div>
                </div>
                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.currentBalance')}</p>
                    <p className="mt-1 text-lg font-bold text-gray-900">{enrollment.current_balance}</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.lifetimeEarned')}</p>
                    <p className="mt-1 text-sm text-gray-700">{enrollment.lifetime_earned}</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.lifetimeRedeemed')}</p>
                    <p className="mt-1 text-sm text-gray-700">{enrollment.lifetime_redeemed}</p>
                  </div>
                </div>

                {/* Transaction History Toggle */}
                <div className="mt-4 pt-3 border-t border-gray-100">
                  <button
                    type="button"
                    onClick={() => { toggleTransactions(enrollment.id); }}
                    className="flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900"
                  >
                    {expandedEnrollments.has(enrollment.id) ? (
                      <ChevronUp className="w-4 h-4" />
                    ) : (
                      <ChevronDown className="w-4 h-4" />
                    )}
                    {t('loyalty:members.transactions')}
                  </button>

                  {expandedEnrollments.has(enrollment.id) && (
                    <TransactionHistorySection memberId={member.id} enrollment={enrollment} />
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <EnrollMemberModal
        isOpen={isEnrollModalOpen}
        onClose={() => { setIsEnrollModalOpen(false); }}
        onSubmit={handleEnroll}
        isPending={enrollMutation.isPending}
      />

      <AdjustPointsModal
        isOpen={adjustTarget !== null}
        onClose={() => { setAdjustTarget(null); }}
        onSubmit={handleAdjust}
        isPending={adjustMutation.isPending}
      />

      <ConfirmDialog
        isOpen={optOutTarget !== null}
        onClose={() => { setOptOutTarget(null); }}
        onConfirm={() => {
          if (optOutTarget) {
            optOutMutation.mutate(
              { memberId: member.id, enrollmentId: optOutTarget.id },
              { onSettled: () => { setOptOutTarget(null); } },
            )
          }
        }}
        isLoading={optOutMutation.isPending}
        title={t('loyalty:actions.optOut')}
        message={t('loyalty:programs.deactivateConfirm')}
        variant="warning"
      />
    </div>
  )
}
