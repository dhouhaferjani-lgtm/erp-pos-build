import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Plus, FileText } from 'lucide-react'
import { cn } from '../../../lib/utils'
import { textColors } from '../../../lib/designTokens'
import { documentRouteTypeFromSource } from '../../../lib/entityRoutes'
import { Button, StatusBadge, statusTone, type StatusTone } from '../../../components/atoms'
import { EntityLink } from '../../../components/molecules/EntityLink'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '../../../components/molecules'
import { OffsetPagination } from '../../../components/ui/OffsetPagination'
import { useJournalEntries } from '../hooks/useJournalEntries'
import type { JournalEntry } from '../types'

const ADD_ROUTE = '/finance/journal-entries/create'

/**
 * Journal-entry statuses that aren't in the shared `statusTone` built-in map
 * get a semantic tone here, so every status renders through the one sanctioned
 * `StatusBadge` palette instead of a bespoke off-theme color map. (`draft` is
 * already built in → `pending`.)
 */
const journalEntryStatusTones: Record<string, StatusTone> = {
  posted: 'success',
}

function JournalSourceLink({ entry }: { entry: JournalEntry }) {
  if (!entry.source_id || !entry.source_type) return null

  const documentType = documentRouteTypeFromSource(entry.source_type)
  if (documentType) {
    return (
      <EntityLink
        type="document"
        id={entry.source_id}
        documentType={documentType}
        label={entry.source_type}
        className="text-xs"
      />
    )
  }

  if (entry.source_type === 'payment') {
    return (
      <EntityLink
        type="payment"
        id={entry.source_id}
        label={entry.source_type}
        className="text-xs"
      />
    )
  }

  if (entry.source_type === 'expense') {
    return (
      <EntityLink
        type="expense"
        id={entry.source_id}
        label={entry.source_type}
        className="text-xs"
      />
    )
  }

  return <span className={cn('text-xs', textColors.tertiary)}>{entry.source_type}</span>
}

export function JournalEntryListPage() {
  const { t } = useTranslation(['finance', 'common'])
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const { data, isLoading } = useJournalEntries(page)

  const entries = data?.data ?? []
  const meta = data?.meta

  const columns: DataTableColumn<JournalEntry>[] = [
    {
      key: 'entry_number',
      header: t('finance:journalEntry.entryNumber'),
      render: (entry) => (
        <EntityLink
          type="journalEntry"
          id={entry.id}
          label={entry.entry_number}
          className="font-mono"
        />
      ),
    },
    {
      key: 'entry_date',
      header: t('finance:journalEntry.entryDate'),
      render: (entry) => format(new Date(entry.entry_date), 'MMM d, yyyy'),
    },
    {
      key: 'description',
      header: t('finance:journalEntry.description'),
      cellClassName: 'max-w-xs truncate',
      render: (entry) => (
        <div className="space-y-1">
          <div>{entry.description ?? '-'}</div>
          <JournalSourceLink entry={entry} />
        </div>
      ),
    },
    {
      key: 'status',
      header: t('common:fields.status'),
      render: (entry) => (
        <StatusBadge tone={statusTone(entry.status, journalEntryStatusTones)}>
          {t(`journalEntry.status.${entry.status}`)}
        </StatusBadge>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('common:table.actionsColumn')}</span>,
      align: 'right',
      render: (entry) => (
        <Button
          variant="ghost"
          size="sm"
          className={cn('font-medium', textColors.brand, 'hover:underline')}
          onClick={() => { void navigate(`/finance/journal-entries/${entry.id}`) }}
        >
          {t('common:actions.view')}
        </Button>
      ),
    },
  ]

  return (
    <ListPageLayout
      title={t('finance:journalEntry.list.title')}
      subtitle={t('finance:journalEntry.list.description')}
      actions={
        <Button className="gap-2" onClick={() => { void navigate(ADD_ROUTE) }}>
          <Plus className="h-4 w-4" />
          {t('finance:journalEntry.new')}
        </Button>
      }
      pagination={
        meta && meta.last_page > 1 ? (
          <OffsetPagination
            currentPage={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            perPage={meta.per_page}
            from={null}
            to={null}
            onPageChange={setPage}
            onPerPageChange={() => { /* per-page not supported by useJournalEntries */ }}
          />
        ) : undefined
      }
    >
      <DataTable
        columns={columns}
        data={entries}
        keyExtractor={(entry) => entry.id}
        isLoading={isLoading}
        emptyState={
          <div className="py-6">
            <EmptyState
              icon={<FileText className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
              title={t('finance:journalEntry.list.empty')}
            />
            <div className="mt-6 flex justify-center">
              <Button className="gap-2" onClick={() => { void navigate(ADD_ROUTE) }}>
                <Plus className="h-4 w-4" />
                {t('finance:journalEntry.list.createFirst')}
              </Button>
            </div>
          </div>
        }
      />
    </ListPageLayout>
  )
}
