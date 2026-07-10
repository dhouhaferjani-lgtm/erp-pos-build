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
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

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
    return <p className={`text-sm ${colorTokens.text.subtle} py-3`}>{t('loyalty:members.noTransactions')}</p>
  }

  return (
    <div className="mt-3">
      <DataTable className="w-full text-sm">
        <thead>
          <tr className={`border-b ${colorTokens.border.subtle} text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
            <th className="py-2 pr-4">{t('loyalty:transactions.date')}</th>
            <th className="py-2 pr-4">{t('loyalty:transactions.type')}</th>
            <th className="py-2 pr-4 text-right">{t('loyalty:transactions.points')}</th>
            <th className="py-2 pr-4 text-right">{t('loyalty:transactions.balanceAfter')}</th>
            <th className="py-2">{t('loyalty:transactions.description')}</th>
          </tr>
        </thead>
        <tbody>
          {transactions.map((tx) => (
            <tr key={tx.id} className={`border-b ${colorTokens.border.hairline}`}>
              <td className={`py-2 pr-4 ${colorTokens.text.muted}`}>
                {new Date(tx.created_at).toLocaleDateString()}
              </td>
              <td className="py-2 pr-4">
                <Badge variant={TRANSACTION_TYPE_VARIANTS[tx.transaction_type]}>
                  {t(`loyalty:transactionTypes.${tx.transaction_type}`)}
                </Badge>
              </td>
              <td className="py-2 pr-4 text-right font-medium">
                <span className={parseFloat(tx.amount) >= 0 ? `${colorTokens.intent.success.text}` : `${colorTokens.intent.danger.text}`}>
                  {parseFloat(tx.amount) >= 0 ? '+' : ''}{tx.amount}
                </span>
              </td>
              <td className={`py-2 pr-4 text-right ${colorTokens.text.secondary}`}>{tx.balance_after}</td>
              <td className={`py-2 ${colorTokens.text.muted} truncate max-w-[200px]`}>{tx.description ?? '-'}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      {meta && meta.last_page > 1 && (
        <div className={`flex items-center justify-between mt-3 pt-3 border-t ${colorTokens.border.hairline}`}>
          <span className={`text-xs ${colorTokens.text.subtle}`}>
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
    return <div className={`text-center py-12 ${colorTokens.text.subtle}`}>{t('common:notFound')}</div>
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
            className={`p-2 rounded-lg ${colorTokens.intent.neutral.bgHoverSoft}`}
          >
            <ArrowLeft className="w-5 h-5" />
          </button>
          <div>
            <div className="flex items-center gap-3">
              <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
                {[member.first_name, member.last_name].filter(Boolean).join(' ') || member.phone}
              </PageHeaderTitle>
              <MemberStatusBadge status={member.status} />
            </div>
            <p className={`text-sm ${colorTokens.text.subtle} mt-1`}>{member.phone}</p>
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
      <div className={`${colorTokens.surface.base} rounded-lg border ${colorTokens.border.subtle} p-6`}>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>{t('loyalty:members.profile')}</h2>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <p className={`text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.phone')}</p>
            <p className={`mt-1 text-sm ${colorTokens.text.primary}`}>{member.phone}</p>
          </div>
          <div>
            <p className={`text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.email')}</p>
            <p className={`mt-1 text-sm ${colorTokens.text.primary}`}>{member.email ?? '-'}</p>
          </div>
          <div>
            <p className={`text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.dateOfBirth')}</p>
            <p className={`mt-1 text-sm ${colorTokens.text.primary}`}>
              {member.date_of_birth ? new Date(member.date_of_birth).toLocaleDateString() : '-'}
            </p>
          </div>
          <div>
            <p className={`text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.enrolledAt')}</p>
            <p className={`mt-1 text-sm ${colorTokens.text.primary}`}>
              {new Date(member.enrollment_date).toLocaleDateString()}
            </p>
          </div>
        </div>
      </div>

      {/* Enrollments */}
      <div>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>{t('loyalty:members.enrollments')}</h2>
        {enrollmentsList.length === 0 ? (
          <div className={`text-center py-8 ${colorTokens.surface.base} rounded-lg border ${colorTokens.border.subtle}`}>
            <p className={`${colorTokens.text.subtle}`}>{t('loyalty:members.noEnrollments')}</p>
          </div>
        ) : (
          <div className="space-y-4">
            {enrollmentsList.map((enrollment) => (
              <div key={enrollment.id} className={`${colorTokens.surface.base} rounded-lg border ${colorTokens.border.subtle} p-5`}>
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
                      className={`p-1.5 rounded ${colorTokens.intent.neutral.bgHoverSoft} ${colorTokens.text.muted}`}
                      title={t('loyalty:actions.adjustPoints')}
                    >
                      <Coins className="w-4 h-4" />
                    </button>
                    {enrollment.status === 'active' ? (
                      <button
                        onClick={() => { setOptOutTarget(enrollment); }}
                        className={`p-1.5 rounded ${colorTokens.intent.notice.bgHover} ${colorTokens.intent.notice.text}`}
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
                        className={`p-1.5 rounded ${colorTokens.intent.success.bgHover} ${colorTokens.intent.success.text}`}
                        title={t('loyalty:actions.reactivate')}
                      >
                        <RefreshCw className="w-4 h-4" />
                      </button>
                    ) : null}
                  </div>
                </div>
                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <p className={`text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.currentBalance')}</p>
                    <p className={`mt-1 text-lg font-bold ${colorTokens.text.primary}`}>{enrollment.current_balance}</p>
                  </div>
                  <div>
                    <p className={`text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.lifetimeEarned')}</p>
                    <p className={`mt-1 text-sm ${colorTokens.text.secondary}`}>{enrollment.lifetime_earned}</p>
                  </div>
                  <div>
                    <p className={`text-xs font-medium ${colorTokens.text.subtle} uppercase`}>{t('loyalty:fields.lifetimeRedeemed')}</p>
                    <p className={`mt-1 text-sm ${colorTokens.text.secondary}`}>{enrollment.lifetime_redeemed}</p>
                  </div>
                </div>

                {/* Transaction History Toggle */}
                <div className={`mt-4 pt-3 border-t ${colorTokens.border.hairline}`}>
                  <button
                    type="button"
                    onClick={() => { toggleTransactions(enrollment.id); }}
                    className={`flex items-center gap-2 text-sm font-medium ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
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
