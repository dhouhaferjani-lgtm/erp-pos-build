import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { Button } from '@/components/atoms'
import type { TechnicianTimeEntry } from '../api/authoringTypes'
import { useDeleteTimeEntry, useTimeEntries } from '../hooks/useAuthoring'
import { TimeEntryFormModal } from './TimeEntryFormModal'


interface TimeEntriesTabProps {
  technicianId: string
}

function startOfWeek(d: Date): Date {
  const copy = new Date(d)
  const day = copy.getDay() // 0 = Sunday
  const diff = (day === 0 ? -6 : 1 - day)
  copy.setDate(copy.getDate() + diff)
  copy.setHours(0, 0, 0, 0)
  return copy
}

function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1)
}

function isLocked(entry: TechnicianTimeEntry): boolean {
  // Spec §5.4.1: Edit/Delete must be disabled when the linked WO is in a
  // locked status (Completed or Invoiced). The backend projects
  // `work_order_status` on TechnicianTimeEntryData so we can compute this
  // synchronously without a per-row fetch.
  return (
    entry.work_order_status === 'completed' ||
    entry.work_order_status === 'invoiced'
  )
}

export function TimeEntriesTab({ technicianId }: TimeEntriesTabProps) {
  const { t } = useTranslation('workshop-technicians')
  const { data, isLoading } = useTimeEntries(technicianId)
  const deleteMut = useDeleteTimeEntry(technicianId)
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<TechnicianTimeEntry | undefined>(undefined)

  const rows = data ?? []

  const { weekMinutes, monthMinutes } = useMemo(() => {
    const now = new Date()
    const weekStart = startOfWeek(now).getTime()
    const monthStart = startOfMonth(now).getTime()
    let week = 0
    let month = 0
    for (const row of rows) {
      const startedMs = new Date(row.started_at).getTime()
      const mins = row.duration_minutes ?? 0
      if (startedMs >= weekStart) week += mins
      if (startedMs >= monthStart) month += mins
    }
    return { weekMinutes: week, monthMinutes: month }
  }, [rows])

  function handleAdd(): void {
    setEditing(undefined)
    setModalOpen(true)
  }

  function handleEdit(row: TechnicianTimeEntry): void {
    setEditing(row)
    setModalOpen(true)
  }

  function handleDelete(row: TechnicianTimeEntry): void {
    if (window.confirm(t('authoring.timeEntries.confirmDelete'))) {
      deleteMut.mutate(row.id)
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h3 className={`text-sm font-semibold ${textColors.primary}`}>
          {t('authoring.timeEntries.title')}
        </h3>
        <Button
          type="button"
          variant="primary"
          size="sm"
          onClick={handleAdd}
          className="inline-flex items-center gap-1"
        >
          <Plus className="h-4 w-4" />
          {t('authoring.timeEntries.add')}
        </Button>
      </div>

      <dl
        className={`grid grid-cols-2 gap-3 rounded-lg border ${borderColors.light} bg-white p-3`}
      >
        <div>
          <dt className={`text-xs uppercase ${textColors.tertiary}`}>
            {t('authoring.timeEntries.weekTotal')}
          </dt>
          <dd className={`text-sm font-semibold ${textColors.primary}`}>
            {t('authoring.timeEntries.hoursShort', {
              hours: (weekMinutes / 60).toFixed(1),
            })}
          </dd>
        </div>
        <div>
          <dt className={`text-xs uppercase ${textColors.tertiary}`}>
            {t('authoring.timeEntries.monthTotal')}
          </dt>
          <dd className={`text-sm font-semibold ${textColors.primary}`}>
            {t('authoring.timeEntries.hoursShort', {
              hours: (monthMinutes / 60).toFixed(1),
            })}
          </dd>
        </div>
      </dl>

      {isLoading ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('team.loading')}</p>
      ) : rows.length === 0 ? (
        <p
          className={`rounded-lg border ${borderColors.light} bg-white p-4 text-sm ${textColors.tertiary}`}
        >
          {t('authoring.timeEntries.empty')}
        </p>
      ) : (
        <ul
          className={`divide-y ${borderColors.divideLight} overflow-hidden rounded-lg border ${borderColors.light} bg-white`}
        >
          {rows.map((row) => {
            const locked = isLocked(row)
            return (
              <li key={row.id} className="flex items-center justify-between gap-3 p-3">
                <div>
                  <p className={`flex items-center gap-2 text-sm font-medium ${textColors.primary}`}>
                    {t(`authoring.timeEntries.entryType.${row.entry_type}`)}
                    {locked ? (
                      <span
                        title={t('authoring.timeEntries.lockedTooltip')}
                        className={`${tokens.badge.base} ${tokens.badge.gray} inline-flex items-center gap-1`}
                      >
                        <Lock className="h-3 w-3" />
                        {t('authoring.timeEntries.lockedBadge')}
                      </span>
                    ) : null}
                  </p>
                  <p className={`text-xs ${textColors.tertiary}`}>
                    {new Date(row.started_at).toLocaleString()}
                    {row.ended_at !== null ? ` → ${new Date(row.ended_at).toLocaleString()}` : ''}
                    {row.duration_minutes !== null
                      ? ` · ${t('authoring.timeEntries.hoursShort', { hours: (row.duration_minutes / 60).toFixed(2) })}`
                      : ''}
                  </p>
                </div>
                <div className="flex items-center gap-1">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      handleEdit(row)
                    }}
                    disabled={locked}
                    aria-label={t('authoring.timeEntries.modal.editTitle')}
                  >
                    <Pencil className="h-4 w-4" />
                  </Button>
                  {/* Button atom has no `dangerOutline` variant (out-of-scope
                      atom change) — kept raw with literal tokens for pixel parity. */}
                  <button
                    type="button"
                    onClick={() => {
                      handleDelete(row)
                    }}
                    disabled={locked}
                    className={`${tokens.button.base} ${tokens.button.dangerOutline} ${tokens.button.sizes.sm}`}
                    aria-label={t('authoring.timeEntries.confirmDelete')}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {modalOpen ? (
        <TimeEntryFormModal
          technicianId={technicianId}
          timeEntry={editing}
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
