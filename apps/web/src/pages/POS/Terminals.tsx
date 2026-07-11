import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Plus, X } from 'lucide-react'
import { PageHeader } from '@/components/molecules/PageHeader'
import { TerminalList, TerminalForm } from '@/features/pos/components'
import {
  useTerminals,
  useCreateTerminal,
  useUpdateTerminal,
  useArchiveTerminal,
  useDeleteTerminal,
  useActivateTerminal,
  useDeactivateTerminal,
  useToggleTrainingMode,
  type Terminal,
  type CreateTerminalInput,
  type UpdateTerminalInput,
} from '@/features/pos/hooks/useTerminals'
import { getLocations } from '@/features/locations/api/locations'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * POS Terminals Management Page
 *
 * Allows administrators to manage POS terminals with full CRUD operations
 */
export function TerminalsPage() {
  const { t } = useTranslation(['common', 'pos'])
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [editingTerminal, setEditingTerminal] = useState<Terminal | undefined>()
  const [archiveTarget, setArchiveTarget] = useState<Terminal | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Terminal | null>(null)
  const [deactivateTarget, setDeactivateTarget] = useState<Terminal | null>(null)
  const [deactivateReason, setDeactivateReason] = useState('')

  // Fetch terminals and locations
  const { data: terminals = [], isLoading: terminalsLoading } = useTerminals()
  const { data: locationsData } = useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: getLocations,
    enabled: !!tenantId && !!companyId,
  })

  const locations = locationsData || []

  // Mutations
  const createTerminal = useCreateTerminal()
  const updateTerminal = useUpdateTerminal()
  const archiveTerminalMutation = useArchiveTerminal()
  const deleteTerminal = useDeleteTerminal()
  const activateTerminal = useActivateTerminal()
  const deactivateTerminal = useDeactivateTerminal()
  const toggleTrainingMode = useToggleTrainingMode()

  // Handle form open
  const handleOpenCreateForm = () => {
    setEditingTerminal(undefined)
    setIsFormOpen(true)
  }

  const handleOpenEditForm = (terminal: Terminal) => {
    setEditingTerminal(terminal)
    setIsFormOpen(true)
  }

  const handleCloseForm = () => {
    setIsFormOpen(false)
    setEditingTerminal(undefined)
  }

  // Handle form submit
  const handleSubmit = async (data: CreateTerminalInput | UpdateTerminalInput) => {
    try {
      if (editingTerminal) {
        await updateTerminal.mutateAsync({
          id: editingTerminal.id,
          data: data as UpdateTerminalInput,
        })
        toast.success(t('pos:messages.terminalUpdated'))
      } else {
        await createTerminal.mutateAsync(data as CreateTerminalInput)
        toast.success(t('pos:messages.terminalCreated'))
      }
      handleCloseForm()
    } catch (_err) {
      toast.error(
        editingTerminal
          ? t('common:common.errorUpdating', { resource: t('pos:terminal.terminal') })
          : t('common:common.errorCreating', { resource: t('pos:terminal.terminal') })
      )
    }
  }

  // Handle archive - show confirmation modal
  const handleArchive = (terminal: Terminal) => {
    setArchiveTarget(terminal)
  }

  const confirmArchive = async () => {
    if (!archiveTarget) return
    try {
      await archiveTerminalMutation.mutateAsync(archiveTarget.id)
      toast.success(t('pos:messages.terminalArchived'))
    } catch (_err) {
      toast.error(t('common:common.error'))
    } finally {
      setArchiveTarget(null)
    }
  }

  // Handle delete - show confirmation modal
  const handleDelete = (terminal: Terminal) => {
    setDeleteTarget(terminal)
  }

  const confirmDelete = async () => {
    if (!deleteTarget) return
    try {
      await deleteTerminal.mutateAsync(deleteTarget.id)
      toast.success(t('pos:messages.terminalDeleted'))
    } catch (_err) {
      toast.error(t('common:common.errorDeleting', { resource: t('pos:terminal.terminal') }))
    } finally {
      setDeleteTarget(null)
    }
  }

  // Handle activate
  const handleActivate = async (terminal: Terminal) => {
    try {
      await activateTerminal.mutateAsync(terminal.id)
      toast.success(t('pos:messages.terminalActivated'))
    } catch (_err) {
      toast.error(t('common:common.error'))
    }
  }

  // Handle training mode toggle
  const handleToggleTraining = async (terminal: Terminal) => {
    try {
      await toggleTrainingMode.mutateAsync(terminal.id)
      toast.success(
        terminal.is_training_mode
          ? t('pos:messages.trainingModeDisabled')
          : t('pos:messages.trainingModeEnabled')
      )
    } catch (_err) {
      toast.error(t('common:common.error'))
    }
  }

  // Handle deactivate - show reason input modal
  const handleDeactivate = (terminal: Terminal) => {
    setDeactivateTarget(terminal)
    setDeactivateReason('')
  }

  const confirmDeactivate = async () => {
    if (!deactivateTarget) return
    try {
      await deactivateTerminal.mutateAsync({
        id: deactivateTarget.id,
        data: deactivateReason ? { reason: deactivateReason } : undefined,
      })
      toast.success(t('pos:messages.terminalDeactivated'))
    } catch (_err) {
      toast.error(t('common:common.error'))
    } finally {
      setDeactivateTarget(null)
      setDeactivateReason('')
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={t('common:navigation.terminals')}
        subtitle={t('pos:terminal.pageDescription')}
        breadcrumb={
          <Link
            to="/pos"
            className={`inline-flex items-center text-sm ${colorTokens.text.subtle} ${colorTokens.variants.hoverTextGray700}`}
          >
            &larr; {t('common:navigation.pos')}
          </Link>
        }
        actions={
          <button
            type="button"
            onClick={handleOpenCreateForm}
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.variants.hoverBgBlue700} focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} focus:ring-offset-2`}
          >
            <Plus className="h-4 w-4" />
            {t('pos:terminal.addTerminal')}
          </button>
        }
      />

      {/* Terminals List */}
      <div className={`${colorTokens.surface.base} shadow rounded-lg overflow-hidden`}>
        <TerminalList
          terminals={terminals}
          isLoading={terminalsLoading}
          onEdit={handleOpenEditForm}
          onArchive={handleArchive}
          onDelete={handleDelete}
          onActivate={handleActivate}
          onDeactivate={handleDeactivate}
          onToggleTraining={(terminal) => void handleToggleTraining(terminal)}
        />
      </div>

      {/* Terminal Form Modal */}
      {isFormOpen && (
        <div className="fixed inset-0 z-50 overflow-y-auto">
          <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            {/* Backdrop - Semi-transparent overlay */}
            <div
              className={`fixed inset-0 ${colorTokens.surface.overlay} transition-opacity`}
              onClick={handleCloseForm}
              aria-hidden="true"
            />

            {/* Modal */}
            <div className={`relative z-10 transform overflow-hidden rounded-lg ${colorTokens.surface.base} text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg`}>
              <div className={`${colorTokens.surface.base} px-4 pb-4 pt-5 sm:p-6 sm:pb-4`}>
                {/* Modal Header */}
                <div className="flex items-center justify-between mb-4">
                  <h3 className={`text-lg font-medium leading-6 ${colorTokens.text.primary}`}>
                    {editingTerminal
                      ? t('pos:terminal.editTerminal')
                      : t('pos:terminal.addTerminal')}
                  </h3>
                  <button
                    type="button"
                    onClick={handleCloseForm}
                    className={`rounded-md ${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHoverSubtle} focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing}`}
                  >
                    <X className="h-5 w-5" />
                  </button>
                </div>

                {/* Form */}
                <TerminalForm
                  terminal={editingTerminal}
                  locations={locations}
                  isSubmitting={
                    createTerminal.isPending || updateTerminal.isPending
                  }
                  onSubmit={handleSubmit}
                  onCancel={handleCloseForm}
                />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Archive Confirmation Modal */}
      {archiveTarget && (
        <div className={`fixed inset-0 z-50 flex items-center justify-center ${colorTokens.surface.overlay}`}>
          <div className={`${colorTokens.surface.base} rounded-lg shadow-xl w-full max-w-sm p-6`}>
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary} mb-2`}>
              {t('pos:terminal.confirmArchiveTitle')}
            </h3>
            <p className={`${colorTokens.text.muted} mb-6`}>
              {t('pos:terminal.confirmArchive', { resource: `${archiveTarget.name} (${archiveTarget.code})` })}
            </p>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => { setArchiveTarget(null); }}
                className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} border ${colorTokens.border.default} rounded-lg ${colorTokens.variants.hoverBgGray50}`}
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="button"
                onClick={() => void confirmArchive()}
                className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.caution.bgStrong} rounded-lg ${colorTokens.variants.hoverBgAmber700}`}
              >
                {t('pos:terminal.archive')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Delete Confirmation Modal */}
      {deleteTarget && (
        <div className={`fixed inset-0 z-50 flex items-center justify-center ${colorTokens.surface.overlay}`}>
          <div className={`${colorTokens.surface.base} rounded-lg shadow-xl w-full max-w-sm p-6`}>
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary} mb-2`}>
              {t('common:common.confirmDeleteTitle')}
            </h3>
            <p className={`${colorTokens.text.muted} mb-6`}>
              {t('common:common.confirmDelete', { resource: `${deleteTarget.name} (${deleteTarget.code})` })}
            </p>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => { setDeleteTarget(null); }}
                className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} border ${colorTokens.border.default} rounded-lg ${colorTokens.variants.hoverBgGray50}`}
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="button"
                onClick={() => void confirmDelete()}
                className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.danger.bgStrong} rounded-lg ${colorTokens.variants.hoverBgRed700}`}
              >
                {t('common:actions.delete')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Deactivate Reason Modal */}
      {deactivateTarget && (
        <div className={`fixed inset-0 z-50 flex items-center justify-center ${colorTokens.surface.overlay}`}>
          <div className={`${colorTokens.surface.base} rounded-lg shadow-xl w-full max-w-sm p-6`}>
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary} mb-2`}>
              {t('pos:terminal.deactivateTerminal')}
            </h3>
            <p className={`${colorTokens.text.muted} mb-4`}>
              {t('pos:terminal.deactivationReasonPrompt')}
            </p>
            <input
              type="text"
              value={deactivateReason}
              onChange={(e) => { setDeactivateReason(e.target.value); }}
              placeholder={t('pos:terminal.deactivationReasonPlaceholder')}
              className={`w-full px-4 py-2 rounded-lg border ${colorTokens.border.default} focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} mb-6`}
            />
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => { setDeactivateTarget(null); setDeactivateReason('') }}
                className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} border ${colorTokens.border.default} rounded-lg ${colorTokens.variants.hoverBgGray50}`}
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="button"
                onClick={() => void confirmDeactivate()}
                className={`flex-1 px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.notice.bgStrong} rounded-lg ${colorTokens.intent.notice.bgStrongHover}`}
              >
                {t('pos:terminal.deactivate')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
