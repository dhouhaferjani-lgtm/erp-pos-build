import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { X, UserPlus, XCircle, CheckCircle } from 'lucide-react'
import { toast } from 'sonner'
import { assignFraudAlert, dismissFraudAlert, resolveFraudAlert, getAdminUsers } from '../api/fraudApi'
import type { FraudAlert } from '../types/fraudAlerts'

interface AssignModalProps {
  alert: FraudAlert
  onClose: () => void
}

export function AssignAlertModal({ alert, onClose }: AssignModalProps) {
  const { t } = useTranslation(['common', 'compliance'])
  const queryClient = useQueryClient()
  const [selectedUserId, setSelectedUserId] = useState<string>('')

  // Fetch admin users
  const { data: adminUsersData, isLoading: loadingUsers } = useQuery({
    queryKey: ['admin-users'],
    queryFn: getAdminUsers,
  })

  const assignMutation = useMutation({
    mutationFn: (userId: string) => assignFraudAlert(alert.id, userId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fraud-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['fraud-alert-statistics'] })
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

  return createPortal(
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg max-w-md w-full">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <div className="flex items-center gap-3">
            <div className="rounded-lg p-2 bg-blue-100 border border-blue-200">
              <UserPlus className="h-5 w-5 text-blue-600" />
            </div>
            <h2 className="text-lg font-semibold text-gray-900">
              {t('compliance:fraudAlerts.modals.assign.title')}
            </h2>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <p className="text-sm text-gray-600">
            {t('compliance:fraudAlerts.modals.assign.selectUser')}
          </p>

          <div>
            <label htmlFor="assignee" className="block text-sm font-medium text-gray-700 mb-2">
              {t('compliance:fraudAlerts.detail.assignedTo')}
            </label>
            {loadingUsers ? (
              <div className="text-center text-sm text-gray-500 py-2">
                {t('common:loading')}
              </div>
            ) : (
              <select
                id="assignee"
                value={selectedUserId}
                onChange={(e) => { setSelectedUserId(e.target.value); }}
                className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
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

          {/* Actions */}
          <div className="flex justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              disabled={assignMutation.isPending || !selectedUserId}
              className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
            >
              {assignMutation.isPending ? t('common:actions.saving') : t('compliance:fraudAlerts.actions.assign')}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  )
}

interface DismissModalProps {
  alert: FraudAlert
  onClose: () => void
}

export function DismissAlertModal({ alert, onClose }: DismissModalProps) {
  const { t } = useTranslation(['common', 'compliance'])
  const queryClient = useQueryClient()
  const [notes, setNotes] = useState('')

  const dismissMutation = useMutation({
    mutationFn: (dismissNotes: string) => dismissFraudAlert(alert.id, dismissNotes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fraud-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['fraud-alert-statistics'] })
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

  return createPortal(
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg max-w-md w-full">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <div className="flex items-center gap-3">
            <div className="rounded-lg p-2 bg-gray-100 border border-gray-200">
              <XCircle className="h-5 w-5 text-gray-600" />
            </div>
            <h2 className="text-lg font-semibold text-gray-900">
              {t('compliance:fraudAlerts.modals.dismiss.title')}
            </h2>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label htmlFor="dismissNotes" className="block text-sm font-medium text-gray-700 mb-2">
              {t('compliance:fraudAlerts.modals.dismiss.notesLabel')}
            </label>
            <textarea
              id="dismissNotes"
              value={notes}
              onChange={(e) => { setNotes(e.target.value); }}
              placeholder={t('compliance:fraudAlerts.modals.dismiss.notesPlaceholder')}
              rows={4}
              className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              required
            />
          </div>

          {/* Actions */}
          <div className="flex justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              disabled={dismissMutation.isPending || !notes.trim()}
              className="px-4 py-2 text-sm font-medium text-white bg-gray-600 rounded-lg hover:bg-gray-700 disabled:opacity-50"
            >
              {dismissMutation.isPending ? t('common:actions.saving') : t('compliance:fraudAlerts.actions.dismiss')}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  )
}

interface ResolveModalProps {
  alert: FraudAlert
  onClose: () => void
}

export function ResolveAlertModal({ alert, onClose }: ResolveModalProps) {
  const { t } = useTranslation(['common', 'compliance'])
  const queryClient = useQueryClient()
  const [notes, setNotes] = useState('')

  const resolveMutation = useMutation({
    mutationFn: (resolveNotes: string) => resolveFraudAlert(alert.id, resolveNotes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fraud-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['fraud-alert-statistics'] })
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

  return createPortal(
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg max-w-md w-full">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <div className="flex items-center gap-3">
            <div className="rounded-lg p-2 bg-green-100 border border-green-200">
              <CheckCircle className="h-5 w-5 text-green-600" />
            </div>
            <h2 className="text-lg font-semibold text-gray-900">
              {t('compliance:fraudAlerts.modals.resolve.title')}
            </h2>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label htmlFor="resolveNotes" className="block text-sm font-medium text-gray-700 mb-2">
              {t('compliance:fraudAlerts.modals.resolve.notesLabel')}
            </label>
            <textarea
              id="resolveNotes"
              value={notes}
              onChange={(e) => { setNotes(e.target.value); }}
              placeholder={t('compliance:fraudAlerts.modals.resolve.notesPlaceholder')}
              rows={4}
              className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              required
            />
          </div>

          {/* Actions */}
          <div className="flex justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              disabled={resolveMutation.isPending || !notes.trim()}
              className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 disabled:opacity-50"
            >
              {resolveMutation.isPending ? t('common:actions.saving') : t('compliance:fraudAlerts.actions.resolve')}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  )
}
