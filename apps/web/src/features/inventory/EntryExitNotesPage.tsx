import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowDownCircle, ArrowLeft, ArrowUpCircle, ClipboardList } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { DataTable, EmptyState, type DataTableColumn } from '../../components/molecules'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import { PageHeader } from '../../components/molecules/PageHeader'
import { StatusBadge, type StatusTone } from '../../components/atoms'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { formatQuantity } from '../../lib/format'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { borderColors, textColors, tokens } from '../../lib/designTokens'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

type DirectionFilter = 'all' | 'in' | 'out'

interface EntryExitNoteLine {
  movement_id: string
  product: {
    id: string
    name: string
  }
  quantity: string
  quantity_before: string
  quantity_after: string
  movement_type: string
  reason: string | null
}

interface EntryExitNote {
  id: string
  direction: 'in' | 'out' | 'flat'
  source_type: string
  source_id: string | null
  source_label: string
  location: {
    id: string
    name: string
  }
  actor: {
    id: string | null
    name: string | null
  }
  timestamp: string
  lines: EntryExitNoteLine[]
}

interface EntryExitNoteResponse {
  data: EntryExitNote[]
  meta?: {
    total?: number
  }
}

const directionTone: Record<EntryExitNote['direction'], StatusTone> = {
  in: 'success',
  out: 'danger',
  flat: 'neutral',
}

export function EntryExitNotesPage() {
  const { t } = useTranslation(['inventory', 'common'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [direction, setDirection] = useState<DirectionFilter>('all')

  const queryKey = tenantScopedKey(['entry-exit-notes', direction, 'all'])
  const { data, isLoading, error } = useQuery({
    queryKey,
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '100' })
      if (direction !== 'all') {
        params.set('direction', direction)
      }
      const response = await api.get<EntryExitNoteResponse>(`/entry-exit-notes?${params.toString()}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const notes = data?.data ?? []

  const filterTabs = useMemo(() => [
    { value: 'all' as DirectionFilter, label: t('common:filters.all'), count: notes.length },
    { value: 'in' as DirectionFilter, label: t('entryExitNotes.filters.in'), count: notes.filter((note) => note.direction === 'in').length },
    { value: 'out' as DirectionFilter, label: t('entryExitNotes.filters.out'), count: notes.filter((note) => note.direction === 'out').length },
  ], [notes, t])

  const columns: DataTableColumn<EntryExitNote>[] = [
    {
      key: 'direction',
      header: t('entryExitNotes.columns.direction'),
      render: (note) => {
        const Icon = note.direction === 'out' ? ArrowUpCircle : ArrowDownCircle
        return (
          <div className="flex items-center gap-2">
            <Icon className={cn('h-4 w-4', note.direction === 'out' ? textColors.error : textColors.success)} />
            <StatusBadge tone={directionTone[note.direction]}>
              {t(`entryExitNotes.direction.${note.direction}`)}
            </StatusBadge>
          </div>
        )
      },
    },
    {
      key: 'source',
      header: t('entryExitNotes.columns.source'),
      render: (note) => (
        <div>
          <div className={cn('font-medium', textColors.primary)}>{note.source_label}</div>
          <div className={cn('text-xs', textColors.tertiary)}>{t(`entryExitNotes.sourceTypes.${note.source_type}`, note.source_type)}</div>
        </div>
      ),
    },
    {
      key: 'location',
      header: t('entryExitNotes.columns.location'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (note) => note.location.name,
    },
    {
      key: 'lines',
      header: t('entryExitNotes.columns.lines'),
      render: (note) => (
        <div className="space-y-1">
          {note.lines.map((line) => (
            <div key={line.movement_id} className="flex items-center justify-between gap-3 text-sm">
              <span className={textColors.primary}>{line.product.name}</span>
              <span className={cn('font-medium tabular-nums', note.direction === 'out' ? textColors.error : textColors.success)}>
                {note.direction === 'out' ? '-' : '+'}{formatQuantity(line.quantity)}
              </span>
            </div>
          ))}
        </div>
      ),
    },
    {
      key: 'actor',
      header: t('entryExitNotes.columns.actor'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (note) => note.actor.name ?? t('movements.system'),
    },
    {
      key: 'date',
      header: t('entryExitNotes.columns.date'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (note) => new Date(note.timestamp).toLocaleString(),
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('entryExitNotes.title')}
        subtitle={t('entryExitNotes.subtitle', { count: data?.meta?.total ?? notes.length })}
        breadcrumb={
          <Link
            to="/inventory"
            className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
        }
        className="mb-0"
      />

      <FilterTabs tabs={filterTabs} value={direction} onChange={setDirection} />

      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('common:errors.operationFailed')}
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={notes}
          keyExtractor={(note) => note.id}
          isLoading={isLoading}
          className={cn('rounded-lg border bg-white', borderColors.light)}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<ClipboardList className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={t('entryExitNotes.empty.title')}
                description={t('entryExitNotes.empty.description')}
              />
            </div>
          }
        />
      )}
    </div>
  )
}
