import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ArrowDownCircle, ArrowUpCircle, Copy, Inbox, Lock } from 'lucide-react'
import { formatCurrency } from '@/lib/format'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { Select } from '@/components/atoms/Select'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge'
import { EntityLink } from '@/components/molecules/EntityLink'
import { EmptyState } from '@/components/molecules/EmptyState'
import { QueryError } from '@/components/QueryError'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { DateRangeFilter } from '@/components/ui/filters/DateRangeFilter'
import { useCompanyStore } from '@/stores/companyStore'
import {
  useRepositoryMovements,
  type MovementDirection,
  type MovementSourceType,
  type RepositoryMovementsFilters,
} from '../hooks/useRepositoryMovements'

interface RepositoryMovementsTabProps {
  repositoryId: string
}

const DIRECTION_OPTIONS: MovementDirection[] = ['in', 'out']

const SOURCE_TYPE_OPTIONS: MovementSourceType[] = [
  'payment',
  'expense',
  'income',
  'refund',
  'fiscal_event',
  'transfer',
  'adjustment',
  'opening_balance',
  'instrument',
]

const directionTone: Record<MovementDirection, StatusTone> = {
  in: 'success',
  out: 'danger',
}

const directionIcon: Record<MovementDirection, typeof ArrowDownCircle> = {
  in: ArrowDownCircle,
  out: ArrowUpCircle,
}

/**
 * Source doc route for a movement, restricted to source types that map onto
 * an ALREADY ESTABLISHED page. Other source types (income, fiscal_event,
 * transfer, adjustment, opening_balance) have no dedicated detail route today
 * — rather than invent one, the caller falls back to rendering the raw
 * source_id with a copy affordance (see `RepositoryMovementsTab` body).
 */
function sourceDocHref(sourceType: MovementSourceType, sourceId: string | null): string | null {
  if (!sourceId) return null

  switch (sourceType) {
    case 'payment':
    case 'refund':
      return `/treasury/payments/${sourceId}`
    case 'expense':
      return `/expenses/${sourceId}/view`
    case 'instrument':
      return `/treasury/instruments/${sourceId}`
    default:
      return null
  }
}

function formatOccurredAt(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function copyToClipboard(id: string): void {
  void navigator.clipboard.writeText(id)
}

export function RepositoryMovementsTab({ repositoryId }: RepositoryMovementsTabProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const [direction, setDirection] = useState<MovementDirection | ''>('')
  const [sourceType, setSourceType] = useState<MovementSourceType | ''>('')
  const [dateFrom, setDateFrom] = useState<string | undefined>(undefined)
  const [dateTo, setDateTo] = useState<string | undefined>(undefined)
  const [page, setPage] = useState(1)

  const filters: RepositoryMovementsFilters = {
    page,
    ...(direction ? { direction } : {}),
    ...(sourceType ? { source_type: sourceType } : {}),
    ...(dateFrom ? { date_from: dateFrom } : {}),
    ...(dateTo ? { date_to: dateTo } : {}),
  }

  const { data, isLoading, error, refetch } = useRepositoryMovements(repositoryId, filters)
  const movements = data?.data ?? []
  const meta = data?.meta

  const formatAmount = (amount: string) =>
    formatCurrency(amount, { currency: companyCurrency, locale: companyLocale })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <QueryError
        error={error}
        onRetry={() => {
          void refetch()
        }}
        title={t('treasury:repositories.movements.loadError')}
      />
    )
  }

  return (
    <div className="space-y-4">
      <div>
        <h2 className={tokens.heading.section}>{t('treasury:repositories.movements.title')}</h2>
        <p className={cn('text-sm', textColors.tertiary)}>
          {t('treasury:repositories.movements.subtitle', { count: meta?.total ?? movements.length })}
        </p>
      </div>

      {/* Filters */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <label className={tokens.label.base} htmlFor="movements-filter-direction">
            {t('treasury:repositories.movements.filters.direction')}
          </label>
          <Select
            id="movements-filter-direction"
            value={direction}
            onChange={(e) => {
              setDirection(e.target.value as MovementDirection | '')
              setPage(1)
            }}
          >
            <option value="">{t('treasury:repositories.movements.filters.directionAll')}</option>
            {DIRECTION_OPTIONS.map((value) => (
              <option key={value} value={value}>
                {t(`treasury:repositories.movements.directions.${value}`)}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <label className={tokens.label.base} htmlFor="movements-filter-source-type">
            {t('treasury:repositories.movements.filters.sourceType')}
          </label>
          <Select
            id="movements-filter-source-type"
            value={sourceType}
            onChange={(e) => {
              setSourceType(e.target.value as MovementSourceType | '')
              setPage(1)
            }}
          >
            <option value="">{t('treasury:repositories.movements.filters.sourceTypeAll')}</option>
            {SOURCE_TYPE_OPTIONS.map((value) => (
              <option key={value} value={value}>
                {t(`treasury:repositories.movements.sourceTypes.${value}`)}
              </option>
            ))}
          </Select>
        </div>

        <DateRangeFilter
          label={t('treasury:repositories.movements.filters.dateRange')}
          fromValue={dateFrom}
          toValue={dateTo}
          onFromChange={(value) => {
            setDateFrom(value)
            setPage(1)
          }}
          onToChange={(value) => {
            setDateTo(value)
            setPage(1)
          }}
        />
      </div>

      {/* Content */}
      {movements.length === 0 ? (
        <div className="py-6">
          <EmptyState
            icon={<Inbox className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
            title={t('treasury:repositories.movements.empty.title')}
            description={t('treasury:repositories.movements.empty.description')}
          />
        </div>
      ) : (
        <div className={cn('overflow-hidden rounded-lg border bg-white', borderColors.light)}>
          <div className="overflow-x-auto">
            {/*
              Hand-rolled deliberately — the shared DataTable has no pagination
              support, and the drill-down-tab genre (ProductMovementsTab, the
              Overview transactions table) hand-rolls for the same reason.
              Pairs with OffsetPagination below. Do not "fix" this toward
              DataTable without adding pagination support to it first.
            */}
            <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
              <thead className={tokens.table.header}>
                <tr>
                  <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('treasury:repositories.movements.columns.date')}
                  </th>
                  <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('treasury:repositories.movements.columns.direction')}
                  </th>
                  <th className={cn('px-6 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('treasury:repositories.movements.columns.amount')}
                  </th>
                  <th className={cn('px-6 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('treasury:repositories.movements.columns.balanceAfter')}
                  </th>
                  <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('treasury:repositories.movements.columns.source')}
                  </th>
                  <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                    {t('treasury:repositories.movements.columns.journalEntry')}
                  </th>
                </tr>
              </thead>
              <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
                {movements.map((movement) => {
                  const Icon = directionIcon[movement.direction]
                  const isIn = movement.direction === 'in'
                  const href = sourceDocHref(movement.source_type, movement.source_id)

                  return (
                    <tr key={movement.id} className={tokens.table.rowHover} data-testid={`movement-row-${movement.id}`}>
                      <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.tertiary)}>
                        {formatOccurredAt(movement.occurred_at)}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        <div className="flex items-center gap-2">
                          <Icon className={cn('h-4 w-4', isIn ? textColors.success : textColors.error)} />
                          <StatusBadge tone={directionTone[movement.direction]}>
                            {t(`treasury:repositories.movements.directions.${movement.direction}`)}
                          </StatusBadge>
                          {movement.recorded_while_frozen && (
                            <span className={cn('inline-flex items-center gap-1 text-xs', textColors.warningDark)}>
                              <Lock className="h-3 w-3" />
                              {t('treasury:repositories.movements.frozenBadge')}
                            </span>
                          )}
                        </div>
                      </td>
                      <td
                        className={cn(
                          'whitespace-nowrap px-6 py-4 text-end text-sm font-semibold tabular-nums',
                          isIn ? textColors.success : textColors.error,
                        )}
                      >
                        {isIn ? '+' : '-'}
                        {formatAmount(movement.amount)}
                      </td>
                      <td className={cn('whitespace-nowrap px-6 py-4 text-end text-sm tabular-nums', textColors.primary)}>
                        {formatAmount(movement.balance_after)}
                      </td>
                      <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.tertiary)}>
                        <div className="flex items-center gap-2">
                          <StatusBadge tone="neutral">
                            {t(`treasury:repositories.movements.sourceTypes.${movement.source_type}`)}
                          </StatusBadge>
                          {movement.source_id ? (
                            href ? (
                              <Link
                                to={href}
                                className={cn('font-mono text-xs hover:underline', textColors.brand)}
                              >
                                {movement.source_id}
                              </Link>
                            ) : (
                              <span className="inline-flex items-center gap-1">
                                <code className={cn('font-mono text-xs', textColors.primary)}>
                                  {movement.source_id}
                                </code>
                                <button
                                  type="button"
                                  onClick={() => { copyToClipboard(movement.source_id ?? ''); }}
                                  className={cn('rounded p-1', textColors.disabled, textColors.hoverSecondary)}
                                  aria-label={t('treasury:repositories.movements.copyId')}
                                >
                                  <Copy className="h-3.5 w-3.5" />
                                </button>
                              </span>
                            )
                          ) : (
                            <span>{t('treasury:repositories.movements.noSourceLink')}</span>
                          )}
                        </div>
                      </td>
                      <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.tertiary)}>
                        {movement.journal_entry_id ? (
                          <EntityLink
                            type="journalEntry"
                            id={movement.journal_entry_id}
                            label={movement.journal_entry_id}
                            className="font-mono text-xs"
                          />
                        ) : (
                          <span>{t('treasury:repositories.movements.noJournalEntry')}</span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {meta && (
            <OffsetPagination
              currentPage={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              perPage={meta.per_page}
              from={null}
              to={null}
              hidePerPage
              onPageChange={setPage}
              onPerPageChange={() => { /* per_page is hardcoded server-side for this endpoint */ }}
            />
          )}
        </div>
      )}
    </div>
  )
}
