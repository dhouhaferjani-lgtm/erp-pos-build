import { useTranslation } from 'react-i18next'
import { Edit2, Trash2, Power, PowerOff, Archive, GraduationCap } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { StatusBadge } from '@/components/atoms/StatusBadge'
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
        <div className={textColors.tertiary}>{t('common.loading')}</div>
      </div>
    )
  }

  if (terminals.length === 0) {
    return (
      <div className="text-center py-12">
        <div className={cn('text-lg font-medium', textColors.primary)}>
          {t('pos.terminal.noTerminals')}
        </div>
        <p className={cn('mt-1', textColors.tertiary)}>
          {t('pos.terminal.noTerminalsDescription')}
        </p>
      </div>
    )
  }

  return (
    <div className="overflow-x-auto">
      <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
        <thead className={tokens.table.header}>
          <tr>
            <th
              scope="col"
              className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}
            >
              {t('pos.terminal.code')}
            </th>
            <th
              scope="col"
              className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}
            >
              {t('pos.terminal.name')}
            </th>
            <th
              scope="col"
              className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}
            >
              {t('pos.terminal.location')}
            </th>
            <th
              scope="col"
              className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}
            >
              {t('pos.terminal.status')}
            </th>
            <th
              scope="col"
              className={cn('px-6 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}
            >
              {t('common.actions')}
            </th>
          </tr>
        </thead>
        <tbody className={cn('divide-y', borderColors.divideDefault)}>
          {terminals.map((terminal) => (
            <tr key={terminal.id} className={tokens.table.rowHover}>
              <td className={cn('px-6 py-4 whitespace-nowrap text-sm font-medium', textColors.primary)}>
                {terminal.code}
              </td>
              <td className="px-6 py-4 whitespace-nowrap">
                <div className={cn('text-sm font-medium', textColors.primary)}>
                  {terminal.name}
                </div>
                {terminal.description && (
                  <div className={cn('text-sm', textColors.tertiary)}>
                    {terminal.description}
                  </div>
                )}
              </td>
              <td className={cn('px-6 py-4 whitespace-nowrap text-sm', textColors.primary)}>
                {terminal.location?.name ?? '-'}
              </td>
              <td className="px-6 py-4 whitespace-nowrap">
                <div className="flex items-center gap-2">
                  <TerminalStatusBadge isActive={terminal.is_active} />
                  {terminal.is_training_mode && (
                    <StatusBadge tone="warning" className="gap-1">
                      <GraduationCap className="h-3 w-3" />
                      {t('pos.terminal.trainingMode')}
                    </StatusBadge>
                  )}
                </div>
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                <div className="flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => { onEdit(terminal) }}
                    className={cn(textColors.brand, textColors.hoverPrimary)}
                    title={t('common.edit')}
                  >
                    <Edit2 className="h-4 w-4" />
                  </button>

                  {terminal.is_active ? (
                    <button
                      type="button"
                      onClick={() => { onDeactivate(terminal) }}
                      className={cn(textColors.warningDark, textColors.hoverPrimary)}
                      title={t('pos.terminal.deactivate')}
                    >
                      <PowerOff className="h-4 w-4" />
                    </button>
                  ) : (
                    <button
                      type="button"
                      onClick={() => { onActivate(terminal) }}
                      className={cn(textColors.success, textColors.hoverPrimary)}
                      title={t('pos.terminal.activate')}
                    >
                      <Power className="h-4 w-4" />
                    </button>
                  )}

                  <button
                    type="button"
                    onClick={() => { onToggleTraining(terminal) }}
                    className={cn(
                      terminal.is_training_mode ? textColors.warningDark : textColors.disabled,
                      textColors.hoverPrimary
                    )}
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
                    className={cn(textColors.warningDark, textColors.hoverPrimary)}
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
                        ? cn(textColors.disabled, 'cursor-not-allowed')
                        : cn(textColors.error, textColors.hoverError)
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
