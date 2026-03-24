import { useTranslation } from 'react-i18next'
import { Edit2, Trash2, Power, PowerOff, Archive, GraduationCap } from 'lucide-react'
import { TerminalStatusBadge } from './TerminalStatusBadge'
import type { Terminal } from '../hooks/useTerminals'

interface TerminalListProps {
  terminals: Terminal[]
  isLoading?: boolean
  onEdit: (terminal: Terminal) => void
  onArchive: (terminal: Terminal) => void
  onDelete: (terminal: Terminal) => void
  onActivate: (terminal: Terminal) => void
  onDeactivate: (terminal: Terminal) => void
  onToggleTraining: (terminal: Terminal) => void
}

/**
 * Table component to display list of terminals with actions
 */
export function TerminalList({
  terminals,
  isLoading = false,
  onEdit,
  onArchive,
  onDelete,
  onActivate,
  onDeactivate,
  onToggleTraining,
}: TerminalListProps) {
  const { t } = useTranslation()

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common.loading')}</div>
      </div>
    )
  }

  if (terminals.length === 0) {
    return (
      <div className="text-center py-12">
        <div className="text-gray-900 text-lg font-medium">
          {t('pos.terminal.noTerminals')}
        </div>
        <p className="mt-1 text-gray-500">
          {t('pos.terminal.noTerminalsDescription')}
        </p>
      </div>
    )
  }

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200">
        <thead className="bg-gray-50">
          <tr>
            <th
              scope="col"
              className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              {t('pos.terminal.code')}
            </th>
            <th
              scope="col"
              className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              {t('pos.terminal.name')}
            </th>
            <th
              scope="col"
              className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              {t('pos.terminal.location')}
            </th>
            <th
              scope="col"
              className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              {t('pos.terminal.status')}
            </th>
            <th
              scope="col"
              className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              {t('common.actions')}
            </th>
          </tr>
        </thead>
        <tbody className="bg-white divide-y divide-gray-200">
          {terminals.map((terminal) => (
            <tr key={terminal.id} className="hover:bg-gray-50">
              <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                {terminal.code}
              </td>
              <td className="px-6 py-4 whitespace-nowrap">
                <div className="text-sm font-medium text-gray-900">
                  {terminal.name}
                </div>
                {terminal.description && (
                  <div className="text-sm text-gray-500">
                    {terminal.description}
                  </div>
                )}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                {terminal.location?.name || '-'}
              </td>
              <td className="px-6 py-4 whitespace-nowrap">
                <div className="flex items-center gap-2">
                  <TerminalStatusBadge isActive={terminal.is_active} />
                  {terminal.is_training_mode && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                      <GraduationCap className="h-3 w-3" />
                      {t('pos.terminal.trainingMode')}
                    </span>
                  )}
                </div>
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                <div className="flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => { onEdit(terminal) }}
                    className="text-blue-600 hover:text-blue-900"
                    title={t('common.edit')}
                  >
                    <Edit2 className="h-4 w-4" />
                  </button>

                  {terminal.is_active ? (
                    <button
                      type="button"
                      onClick={() => { onDeactivate(terminal) }}
                      className="text-orange-600 hover:text-orange-900"
                      title={t('pos.terminal.deactivate')}
                    >
                      <PowerOff className="h-4 w-4" />
                    </button>
                  ) : (
                    <button
                      type="button"
                      onClick={() => { onActivate(terminal) }}
                      className="text-green-600 hover:text-green-900"
                      title={t('pos.terminal.activate')}
                    >
                      <Power className="h-4 w-4" />
                    </button>
                  )}

                  <button
                    type="button"
                    onClick={() => { onToggleTraining(terminal) }}
                    className={
                      terminal.is_training_mode
                        ? 'text-amber-600 hover:text-amber-900'
                        : 'text-gray-400 hover:text-amber-600'
                    }
                    title={
                      terminal.is_training_mode
                        ? t('pos.terminal.disableTraining')
                        : t('pos.terminal.enableTraining')
                    }
                  >
                    <GraduationCap className="h-4 w-4" />
                  </button>

                  <button
                    type="button"
                    onClick={() => { onArchive(terminal) }}
                    className="text-amber-600 hover:text-amber-900"
                    title={t('pos.terminal.archive')}
                  >
                    <Archive className="h-4 w-4" />
                  </button>

                  <button
                    type="button"
                    onClick={() => { onDelete(terminal) }}
                    disabled={terminal.has_history}
                    className={
                      terminal.has_history
                        ? 'text-gray-300 cursor-not-allowed'
                        : 'text-red-600 hover:text-red-900'
                    }
                    title={
                      terminal.has_history
                        ? t('pos.terminal.cannotDeleteHasHistory')
                        : t('common.delete')
                    }
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
