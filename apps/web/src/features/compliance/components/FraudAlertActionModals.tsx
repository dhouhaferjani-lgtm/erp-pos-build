import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { UserPlus, XCircle, CheckCircle } from 'lucide-react'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { assignFraudAlert, dismissFraudAlert, resolveFraudAlert, getUsersWithAdminRole } from '../api/fraudApi'
import {
  fraudAlertStatisticsInvalidationPredicate,
  fraudAlertsInvalidationPredicate,
} from '../_invalidation'
import type { FraudAlert } from '../types/fraudAlerts'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface AssignModalProps {
  alert: FraudAlert
  onClose: () => void
}

export function AssignAlertModal({ alert, onClose }: AssignModalProps) {
  const { t } = useTranslation(['common', 'compliance'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [selectedUserId, setSelectedUserId] = useState<string>('')

  // Fetch tenant-scoped users with admin role (NOT super-admin users).
  // queryKey is namespaced under 'users' (the tenant-scoped domain) with
  // a sub-segment 'admin-role' so it doesn't collide with the super-admin
  // 'admin' cache namespace used by features/admin/.
  const { data: adminUsersData, isLoading: loadingUsers } = useQuery({
    queryKey: tenantScopedKey(['users', 'admin-role']),
    queryFn: getUsersWithAdminRole,
    enabled: !!tenantId && !!companyId,
  })

  const assignMutation = useMutation({
    mutationFn: (userId: string) => assignFraudAlert(alert.id, userId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: fraudAlertsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: fraudAlertStatisticsInvalidationPredicate(tenantId, companyId),
        }),
      ])
      toast.success(t('compliance:fraudAlerts.messages.assignSuccess'))
      onClose()
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.generic'))
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedUserId) return
    assignMutation.mutate(selectedUserId)
  }

  return (
    <Modal isOpen onClose={onClose} size="md">
      <ModalHeader onClose={onClose} className={`border-b ${colorTokens.border.subtle} pb-4`}>
        <div className="flex items-center gap-3">
          <div className={`rounded-lg p-2 ${colorTokens.intent.primary.bgSoft} border ${colorTokens.intent.primary.borderSubtleSoft}`}>
            <UserPlus className={`h-5 w-5 ${colorTokens.intent.primary.text}`} />
          </div>
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('compliance:fraudAlerts.modals.assign.title')}
          </h2>
        </div>
      </ModalHeader>

      <form onSubmit={handleSubmit}>
        <ModalContent className="space-y-4">
          <p className={`text-sm ${colorTokens.text.muted}`}>
            {t('compliance:fraudAlerts.modals.assign.selectUser')}
          </p>

          <div>
            <label htmlFor="assignee" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('compliance:fraudAlerts.detail.assignedTo')}
            </label>
            {loadingUsers ? (
              <div className={`text-center text-sm ${colorTokens.text.subtle} py-2`}>
                {t('common:loading')}
              </div>
            ) : (
              <select
                id="assignee"
                value={selectedUserId}
                onChange={(e) => { setSelectedUserId(e.target.value); }}
                className={`block w-full rounded-lg ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing}`}
                required
              >
                <option value="">{t('common:actions.select')}</option>
                {adminUsersData?.data.map((user) => (
                  <option key={user.id} value={user.id}>
                    {user.name} ({user.email})
                  </option>
                ))}
              </select>
            )}
          </div>
        </ModalContent>

        <ModalFooter>
          <button
            type="button"
            onClick={onClose}
            className={`px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-lg ${colorTokens.intent.neutral.bgHover}`}
          >
            {t('common:actions.cancel')}
          </button>
          <button
            type="submit"
            disabled={assignMutation.isPending || !selectedUserId}
            className={`px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-lg ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50`}
          >
            {assignMutation.isPending ? t('common:actions.saving') : t('compliance:fraudAlerts.actions.assign')}
          </button>
        </ModalFooter>
      </form>
    </Modal>
  )
}

interface DismissModalProps {
  alert: FraudAlert
  onClose: () => void
}

export function DismissAlertModal({ alert, onClose }: DismissModalProps) {
  const { t } = useTranslation(['common', 'compliance'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [notes, setNotes] = useState('')

  const dismissMutation = useMutation({
    mutationFn: (dismissNotes: string) => dismissFraudAlert(alert.id, dismissNotes),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: fraudAlertsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: fraudAlertStatisticsInvalidationPredicate(tenantId, companyId),
        }),
      ])
      toast.success(t('compliance:fraudAlerts.messages.dismissSuccess'))
      onClose()
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.generic'))
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!notes.trim()) return
    dismissMutation.mutate(notes)
  }

  return (
    <Modal isOpen onClose={onClose} size="md">
      <ModalHeader onClose={onClose} className={`border-b ${colorTokens.border.subtle} pb-4`}>
        <div className="flex items-center gap-3">
          <div className={`rounded-lg p-2 ${colorTokens.surface.muted} border ${colorTokens.border.subtle}`}>
            <XCircle className={`h-5 w-5 ${colorTokens.text.muted}`} />
          </div>
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('compliance:fraudAlerts.modals.dismiss.title')}
          </h2>
        </div>
      </ModalHeader>

      <form onSubmit={handleSubmit}>
        <ModalContent className="space-y-4">
          <div>
            <label htmlFor="dismissNotes" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('compliance:fraudAlerts.modals.dismiss.notesLabel')}
            </label>
            <textarea
              id="dismissNotes"
              value={notes}
              onChange={(e) => { setNotes(e.target.value); }}
              placeholder={t('compliance:fraudAlerts.modals.dismiss.notesPlaceholder')}
              rows={4}
              className={`block w-full rounded-lg ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing}`}
              required
            />
          </div>
        </ModalContent>

        <ModalFooter>
          <button
            type="button"
            onClick={onClose}
            className={`px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-lg ${colorTokens.intent.neutral.bgHover}`}
          >
            {t('common:actions.cancel')}
          </button>
          <button
            type="submit"
            disabled={dismissMutation.isPending || !notes.trim()}
            className={`px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.neutral.bgStrong} rounded-lg ${colorTokens.intent.neutral.bgStrongHover} disabled:opacity-50`}
          >
            {dismissMutation.isPending ? t('common:actions.saving') : t('compliance:fraudAlerts.actions.dismiss')}
          </button>
        </ModalFooter>
      </form>
    </Modal>
  )
}

interface ResolveModalProps {
  alert: FraudAlert
  onClose: () => void
}

export function ResolveAlertModal({ alert, onClose }: ResolveModalProps) {
  const { t } = useTranslation(['common', 'compliance'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [notes, setNotes] = useState('')

  const resolveMutation = useMutation({
    mutationFn: (resolveNotes: string) => resolveFraudAlert(alert.id, resolveNotes),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: fraudAlertsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: fraudAlertStatisticsInvalidationPredicate(tenantId, companyId),
        }),
      ])
      toast.success(t('compliance:fraudAlerts.messages.resolveSuccess'))
      onClose()
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.generic'))
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!notes.trim()) return
    resolveMutation.mutate(notes)
  }

  return (
    <Modal isOpen onClose={onClose} size="md">
      <ModalHeader onClose={onClose} className={`border-b ${colorTokens.border.subtle} pb-4`}>
        <div className="flex items-center gap-3">
          <div className={`rounded-lg p-2 ${colorTokens.intent.success.bgSoft} border ${colorTokens.intent.success.borderSubtle}`}>
            <CheckCircle className={`h-5 w-5 ${colorTokens.intent.success.text}`} />
          </div>
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('compliance:fraudAlerts.modals.resolve.title')}
          </h2>
        </div>
      </ModalHeader>

      <form onSubmit={handleSubmit}>
        <ModalContent className="space-y-4">
          <div>
            <label htmlFor="resolveNotes" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('compliance:fraudAlerts.modals.resolve.notesLabel')}
            </label>
            <textarea
              id="resolveNotes"
              value={notes}
              onChange={(e) => { setNotes(e.target.value); }}
              placeholder={t('compliance:fraudAlerts.modals.resolve.notesPlaceholder')}
              rows={4}
              className={`block w-full rounded-lg ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing}`}
              required
            />
          </div>
        </ModalContent>

        <ModalFooter>
          <button
            type="button"
            onClick={onClose}
            className={`px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-lg ${colorTokens.intent.neutral.bgHover}`}
          >
            {t('common:actions.cancel')}
          </button>
          <button
            type="submit"
            disabled={resolveMutation.isPending || !notes.trim()}
            className={`px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.success.bgStrong} rounded-lg ${colorTokens.intent.success.bgStrongHover} disabled:opacity-50`}
          >
            {resolveMutation.isPending ? t('common:actions.saving') : t('compliance:fraudAlerts.actions.resolve')}
          </button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
