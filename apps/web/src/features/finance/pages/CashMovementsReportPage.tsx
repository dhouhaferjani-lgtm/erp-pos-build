import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ArrowDownCircle, ArrowUpCircle, Copy, Inbox } from 'lucide-react'

import { QueryError } from '@/components/QueryError'
import { Button, Select, StatusBadge, type StatusTone } from '@/components/atoms'
import {
  DataTable,
  type DataTableColumn,
} from '@/components/molecules/DataTable'
import { EmptyState } from '@/components/molecules/EmptyState'
import { PageHeader } from '@/components/molecules/PageHeader'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { DateRangeFilter } from '@/components/ui/filters/DateRangeFilter'
import { usePaymentRepositories } from '@/features/treasury/hooks/usePaymentRepositories'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'
import {
  useCashMovementsReport,
  type CashMovementRow,
  type CashMovementsFilters,
} from '../hooks/useCashMovementsReport'
import {
  getCurrentMonthStartInputValue,
  getTodayDateInputValue,
} from './reportPageUtils'

type MovementDirection = CashMovementRow['direction']

const directionTone: Record<MovementDirection, StatusTone> = {
  in: 'success',
  out: 'danger',
}

const directionIcon: Record<MovementDirection, typeof ArrowDownCircle> = {
  in: ArrowDownCircle,
  out: ArrowUpCircle,
}

function paymentSourceHref(sourceType: string, sourceId: string): string | null {
  return sourceType === 'payment' ? '/treasury/payments/' + sourceId : null
}

function copySourceRef(sourceId: string): void {
  void navigator.clipboard.writeText(sourceId)
}

export function CashMovementsReportPage() {
  const { t } = useTranslation(['finance', 'common'])
  const [from, setFrom] = useState(() => getCurrentMonthStartInputValue())
  const [to, setTo] = useState(() => getTodayDateInputValue())
  const [repositoryId, setRepositoryId] = useState('')
  const [direction, setDirection] = useState<MovementDirection | ''>('')
  const [page, setPage] = useState(1)

  const filters: CashMovementsFilters = {
    from,
    to,
    page,
    ...(repositoryId ? { repository_id: repositoryId } : {}),
    ...(direction ? { direction } : {}),
  }
  const reportQuery = useCashMovementsReport(filters)
  const repositoriesQuery = usePaymentRepositories()
  const rows = reportQuery.data?.data ?? []
  const meta = reportQuery.data?.meta
  const repositories = repositoriesQuery.data ?? []
  const totals = Object.entries(meta?.totals ?? {}).sort(([first], [second]) =>
    first.localeCompare(second),
  )

  const resetPage = () => {
    setPage(1)
  }

  const columns: DataTableColumn<CashMovementRow>[] = [
    {
      key: 'date',
      header: t('finance:cashMovements.columns.date'),
      accessor: (movement) => movement.date,
      cellClassName: cn('whitespace-nowrap', textColors.tertiary),
    },
    {
      key: 'direction',
      header: t('finance:cashMovements.columns.direction'),
      render: (movement) => {
        const Icon = directionIcon[movement.direction]
        const isIn = movement.direction === 'in'

        return (
          <div className="flex items-center gap-2">
            <Icon className={cn('h-4 w-4', isIn ? textColors.success : textColors.error)} />
            <StatusBadge tone={directionTone[movement.direction]}>
              {t('finance:cashMovements.directions.' + movement.direction)}
            </StatusBadge>
          </div>
        )
      },
      cellClassName: 'whitespace-nowrap',
    },
    {
      key: 'amount',
      header: t('finance:cashMovements.columns.amount'),
      numeric: true,
      render: (movement) => {
        const isIn = movement.direction === 'in'

        return (
          <span className={cn('font-semibold', isIn ? textColors.success : textColors.error)}>
            {isIn ? '+' : '−'}
            {formatCurrency(movement.amount, { currency: movement.currency })}
          </span>
        )
      },
      cellClassName: 'whitespace-nowrap',
    },
    {
      key: 'sourceType',
      header: t('finance:cashMovements.columns.sourceType'),
      render: (movement) => (
        <StatusBadge>
          {t('finance:cashMovements.sourceTypes.' + movement.source_type, {
            defaultValue: movement.source_type,
          })}
        </StatusBadge>
      ),
      cellClassName: cn('whitespace-nowrap', textColors.tertiary),
    },
    {
      key: 'counterparty',
      header: t('finance:cashMovements.columns.counterparty'),
      render: (movement) => movement.counterparty ?? '—',
    },
    {
      key: 'glAccount',
      header: t('finance:cashMovements.columns.glAccount'),
      render: (movement) => movement.gl_account ?? '—',
      cellClassName: 'whitespace-nowrap font-mono',
    },
    {
      key: 'sourceRef',
      header: t('finance:cashMovements.columns.sourceRef'),
      render: (movement) => {
        const sourceHref = paymentSourceHref(
          movement.source_type,
          movement.source_id,
        )

        return sourceHref ? (
          <Link className={cn('font-mono text-xs hover:underline', textColors.brand)} to={sourceHref}>
            {movement.source_id}
          </Link>
        ) : (
          <span className="inline-flex items-center gap-1">
            <code className="font-mono text-xs">{movement.source_id}</code>
            <Button
              type="button"
              variant="ghost"
              size="xs"
              onClick={() => {
                copySourceRef(movement.source_id)
              }}
              className="h-7 w-7 p-1"
              aria-label={t('finance:cashMovements.copySourceRef')}
            >
              <Copy className="h-3.5 w-3.5" />
            </Button>
          </span>
        )
      },
      cellClassName: 'whitespace-nowrap',
    },
  ]

  return (
    <div className="space-y-6 p-6">
      <PageHeader
        title={t('finance:cashMovements.title')}
        subtitle={t('finance:cashMovements.subtitle')}
        className="mb-0"
      />

      <section className={cn('rounded-lg border p-4', colors.white, borderColors.light)}>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <DateRangeFilter
            label={t('finance:cashMovements.filters.dateRange')}
            fromValue={from}
            toValue={to}
            onFromChange={(value) => {
              setFrom(value ?? '')
              resetPage()
            }}
            onToChange={(value) => {
              setTo(value ?? '')
              resetPage()
            }}
          />

          <div>
            <label className={tokens.label.base} htmlFor="cash-movements-repository">
              {t('finance:cashMovements.filters.repository')}
            </label>
            <Select
              id="cash-movements-repository"
              value={repositoryId}
              disabled={repositoriesQuery.isLoading}
              onChange={(event) => {
                setRepositoryId(event.target.value)
                resetPage()
              }}
            >
              <option value="">{t('finance:cashMovements.filters.allRepositories')}</option>
              {repositories.map((repository) => (
                <option key={repository.id} value={repository.id}>
                  {repository.code} — {repository.name}
                </option>
              ))}
            </Select>
          </div>

          <div>
            <label className={tokens.label.base} htmlFor="cash-movements-direction">
              {t('finance:cashMovements.filters.direction')}
            </label>
            <Select
              id="cash-movements-direction"
              value={direction}
              onChange={(event) => {
                setDirection(event.target.value as MovementDirection | '')
                resetPage()
              }}
            >
              <option value="">{t('finance:cashMovements.filters.allDirections')}</option>
              <option value="in">{t('finance:cashMovements.directions.in')}</option>
              <option value="out">{t('finance:cashMovements.directions.out')}</option>
            </Select>
          </div>
        </div>
      </section>

      {reportQuery.isLoading ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>
          {t('finance:cashMovements.loading')}
        </p>
      ) : reportQuery.error ? (
        <QueryError
          error={reportQuery.error}
          onRetry={() => {
            void reportQuery.refetch()
          }}
          title={t('finance:cashMovements.loadError')}
        />
      ) : (
        <>
          <section
            aria-label={t('finance:cashMovements.totals.title')}
            className={cn('overflow-hidden rounded-lg border', colors.white, borderColors.light)}
          >
            <div className={cn('grid grid-cols-4 gap-3 px-4 py-3 text-xs font-medium uppercase tracking-wider', tokens.table.header, textColors.tertiary)}>
              <span>{t('finance:cashMovements.totals.currency')}</span>
              <span className="text-end">{t('finance:cashMovements.totals.in')}</span>
              <span className="text-end">{t('finance:cashMovements.totals.out')}</span>
              <span className="text-end">{t('finance:cashMovements.totals.net')}</span>
            </div>
            {totals.length === 0 ? (
              <p className={cn('px-4 py-3 text-sm', textColors.tertiary)}>
                {t('finance:cashMovements.totals.empty')}
              </p>
            ) : (
              <div className={cn('divide-y', borderColors.divideDefault)}>
                {totals.map(([currency, currencyTotals]) => (
                  <div
                    key={currency}
                    data-testid={'cash-movements-total-' + currency}
                    className="grid grid-cols-4 gap-3 px-4 py-3 text-sm tabular-nums"
                  >
                    <span className={cn('font-semibold', textColors.primary)}>{currency}</span>
                    <span className={cn('text-end font-medium', textColors.success)}>
                      {formatCurrency(currencyTotals.in, { currency })}
                    </span>
                    <span className={cn('text-end font-medium', textColors.error)}>
                      {formatCurrency(currencyTotals.out, { currency })}
                    </span>
                    <span className={cn('text-end font-semibold', textColors.primary)}>
                      {formatCurrency(currencyTotals.net, { currency })}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </section>

          {rows.length === 0 ? (
            <EmptyState
              icon={<Inbox className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
              title={t('finance:cashMovements.empty.title')}
              description={t('finance:cashMovements.empty.description')}
            />
          ) : (
            <section className={cn('overflow-hidden rounded-lg border', colors.white, borderColors.light)}>
              <DataTable
                columns={columns}
                data={rows}
                keyExtractor={(movement) =>
                  [
                    movement.source_type,
                    movement.source_id,
                    movement.direction,
                    movement.gl_account,
                  ].join('-')
                }
              />

              {meta && (
                <OffsetPagination
                  currentPage={meta.current_page}
                  lastPage={meta.last_page}
                  total={meta.total}
                  perPage={meta.per_page}
                  from={meta.from}
                  to={meta.to}
                  onPageChange={setPage}
                  onPerPageChange={() => undefined}
                  hidePerPage
                />
              )}
            </section>
          )}
        </>
      )}
    </div>
  )
}
