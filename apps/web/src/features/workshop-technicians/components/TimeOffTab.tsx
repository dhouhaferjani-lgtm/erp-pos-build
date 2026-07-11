import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import type { TechnicianTimeOff } from '../api/authoringTypes'
import { useDeleteTimeOff, useTimeOff } from '../hooks/useAuthoring'
import { TimeOffFormModal } from './TimeOffFormModal'

const buttonTokens = tokens.button


interface TimeOffTabProps {
  technicianId: string
}

export function TimeOffTab({ technicianId }: TimeOffTabProps) {
  const { t } = useTranslation('workshop-technicians')
  const { data, isLoading } = useTimeOff(technicianId)
  const deleteMut = useDeleteTimeOff(technicianId)
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<TechnicianTimeOff | undefined>(undefined)

  function handleAdd(): void {
    setEditing(undefined)
    setModalOpen(true)
  }

  function handleEdit(row: TechnicianTimeOff): void {
    setEditing(row)
    setModalOpen(true)
  }

  function handleDelete(row: TechnicianTimeOff): void {
    if (window.confirm(t('authoring.timeOff.confirmDelete'))) {
      deleteMut.mutate(row.id)
    }
  }

  const rows = data ?? []

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h3 className={`text-sm font-semibold ${textColors.primary}`}>
          {t('authoring.timeOff.title')}
        </h3>
        <button
          type="button"
          onClick={handleAdd}
          className={`${buttonTokens.base} ${buttonTokens.primary} ${buttonTokens.sizes.sm} inline-flex items-center gap-1`}
        >
          <Plus className="h-4 w-4" />
          {t('authoring.timeOff.add')}
        </button>
      </div>

      {isLoading ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('team.loading')}</p>
      ) : rows.length === 0 ? (
        <p
          className={`rounded-lg border ${borderColors.light} bg-white p-4 text-sm ${textColors.tertiary}`}
        >
          {t('authoring.timeOff.empty')}
        </p>
      ) : (
        <ul
          className={`divide-y ${borderColors.divideLight} overflow-hidden rounded-lg border ${borderColors.light} bg-white`}
        >
          {rows.map((row) => (
            <li key={row.id} className="flex items-center justify-between gap-3 p-3">
              <div>
                <p className={`text-sm font-medium ${textColors.primary}`}>
                  {t(`authoring.timeOff.reason.${row.reason_code}`)}
                </p>
                <p className={`text-xs ${textColors.tertiary}`}>
                  {new Date(row.starts_at).toLocaleString()} →{' '}
                  {new Date(row.ends_at).toLocaleString()}
                </p>
              </div>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  onClick={() => {
                    handleEdit(row)
                  }}
                  className={`${buttonTokens.base} ${buttonTokens.ghost} ${buttonTokens.sizes.sm}`}
                  aria-label={t('authoring.timeOff.modal.editTitle')}
                >
                  <Pencil className="h-4 w-4" />
                </button>
                <button
                  type="button"
                  onClick={() => {
                    handleDelete(row)
                  }}
                  className={`${buttonTokens.base} ${buttonTokens.dangerOutline} ${buttonTokens.sizes.sm}`}
                  aria-label={t('authoring.timeOff.confirmDelete')}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {modalOpen ? (
        <TimeOffFormModal
          technicianId={technicianId}
          timeOff={editing}
          onClose={() => {
            setModalOpen(false)
          }}
          onSaved={() => {
            setModalOpen(false)
          }}
        />
      ) : null}
    </div>
  )
}
