import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Plus, X } from 'lucide-react'
import { TerminalList, TerminalForm } from '@/features/pos/components'
import {
  useTerminals,
  useCreateTerminal,
  useUpdateTerminal,
  useDeleteTerminal,
  useActivateTerminal,
  useDeactivateTerminal,
  type Terminal,
  type CreateTerminalInput,
  type UpdateTerminalInput,
} from '@/features/pos/hooks/useTerminals'
import { getLocations } from '@/features/locations/api/locations'
import { toast } from 'sonner'

/**
 * POS Terminals Management Page
 *
 * Allows administrators to manage POS terminals with full CRUD operations
 */
export function TerminalsPage() {
  const { t } = useTranslation()
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [editingTerminal, setEditingTerminal] = useState<Terminal | undefined>()
  const [deleteTarget, setDeleteTarget] = useState<Terminal | null>(null)
  const [deactivateTarget, setDeactivateTarget] = useState<Terminal | null>(null)
  const [deactivateReason, setDeactivateReason] = useState('')

  // Fetch terminals and locations
  const { data: terminals = [], isLoading: terminalsLoading } = useTerminals()
  const { data: locationsData } = useQuery({
    queryKey: ['locations'],
    queryFn: getLocations,
  })

  const locations = locationsData || []

  // Mutations
  const createTerminal = useCreateTerminal()
  const updateTerminal = useUpdateTerminal()
  const deleteTerminal = useDeleteTerminal()
  const activateTerminal = useActivateTerminal()
  const deactivateTerminal = useDeactivateTerminal()

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
        toast.success(t('pos.messages.terminalUpdated'))
      } else {
        await createTerminal.mutateAsync(data as CreateTerminalInput)
        toast.success(t('pos.messages.terminalCreated'))
      }
      handleCloseForm()
    } catch (_err) {
      toast.error(
        editingTerminal
          ? t('common.errorUpdating', { resource: t('pos.terminal.terminal') })
          : t('common.errorCreating', { resource: t('pos.terminal.terminal') })
      )
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
      toast.success(t('pos.messages.terminalDeleted'))
    } catch (_err) {
      toast.error(t('common.errorDeleting', { resource: t('pos.terminal.terminal') }))
    } finally {
      setDeleteTarget(null)
    }
  }

  // Handle activate
  const handleActivate = async (terminal: Terminal) => {
    try {
      await activateTerminal.mutateAsync(terminal.id)
      toast.success(t('pos.messages.terminalActivated'))
    } catch (_err) {
      toast.error(t('common.error'))
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
      toast.success(t('pos.messages.terminalDeactivated'))
    } catch (_err) {
      toast.error(t('common.error'))
    } finally {
      setDeactivateTarget(null)
      setDeactivateReason('')
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">
            {t('navigation.terminals')}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {t('pos.terminal.pageDescription')}
          </p>
        </div>
        <button
          type="button"
          onClick={handleOpenCreateForm}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
        >
          <Plus className="h-4 w-4" />
          {t('pos.terminal.addTerminal')}
        </button>
      </div>

      {/* Terminals List */}
      <div className="bg-white shadow rounded-lg overflow-hidden">
        <TerminalList
          terminals={terminals}
          isLoading={terminalsLoading}
          onEdit={handleOpenEditForm}
          onDelete={handleDelete}
          onActivate={handleActivate}
          onDeactivate={handleDeactivate}
        />
      </div>

      {/* Terminal Form Modal */}
      {isFormOpen && (
        <div className="fixed inset-0 z-50 overflow-y-auto">
          <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            {/* Backdrop - Semi-transparent overlay */}
            <div
              className="fixed inset-0 bg-black/50 transition-opacity"
              onClick={handleCloseForm}
              aria-hidden="true"
            />

            {/* Modal */}
            <div className="relative z-10 transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
              <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                {/* Modal Header */}
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-lg font-medium leading-6 text-gray-900">
                    {editingTerminal
                      ? t('pos.terminal.editTerminal')
                      : t('pos.terminal.addTerminal')}
                  </h3>
                  <button
                    type="button"
                    onClick={handleCloseForm}
                    className="rounded-md text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
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

      {/* Delete Confirmation Modal */}
      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-2">
              {t('common.confirmDeleteTitle')}
            </h3>
            <p className="text-gray-600 mb-6">
              {t('common.confirmDelete', { resource: `${deleteTarget.name} (${deleteTarget.code})` })}
            </p>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => setDeleteTarget(null)}
                className="flex-1 px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50"
              >
                {t('common.actions.cancel')}
              </button>
              <button
                type="button"
                onClick={() => void confirmDelete()}
                className="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700"
              >
                {t('common.actions.delete')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Deactivate Reason Modal */}
      {deactivateTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-2">
              {t('pos.terminal.deactivateTerminal')}
            </h3>
            <p className="text-gray-600 mb-4">
              {t('pos.terminal.deactivationReasonPrompt')}
            </p>
            <input
              type="text"
              value={deactivateReason}
              onChange={(e) => setDeactivateReason(e.target.value)}
              placeholder={t('pos.terminal.deactivationReasonPlaceholder')}
              className="w-full px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 mb-6"
            />
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => { setDeactivateTarget(null); setDeactivateReason('') }}
                className="flex-1 px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50"
              >
                {t('common.actions.cancel')}
              </button>
              <button
                type="button"
                onClick={() => void confirmDeactivate()}
                className="flex-1 px-4 py-2 text-sm font-medium text-white bg-orange-600 rounded-lg hover:bg-orange-700"
              >
                {t('pos.terminal.deactivate')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
