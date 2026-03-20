import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/utils'
import { Button } from '../atoms'

export interface OffsetPaginationProps {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
  from: number | null
  to: number | null
  onPageChange: (page: number) => void
  onPerPageChange: (perPage: number) => void
  className?: string
}

const PER_PAGE_OPTIONS = [10, 25, 50, 100]

export function OffsetPagination({
  currentPage,
  lastPage,
  total,
  perPage,
  from,
  to,
  onPageChange,
  onPerPageChange,
  className,
}: OffsetPaginationProps) {
  const { t } = useTranslation('common')

  const hasPrev = currentPage > 1
  const hasNext = currentPage < lastPage

  const handlePrev = () => {
    if (hasPrev) {
      onPageChange(currentPage - 1)
    }
  }

  const handleNext = () => {
    if (hasNext) {
      onPageChange(currentPage + 1)
    }
  }

  return (
    <div className={cn('flex items-center justify-between px-4 py-3 border-t bg-white', className)}>
      {/* Showing info */}
      <div className="flex items-center gap-4">
        <span className="text-sm text-gray-600">
          {from && to ? (
            t('showing', { from, to, total })
          ) : (
            `${total} ${total === 1 ? t('item') : t('items')}`
          )}
        </span>

        {/* Per page selector */}
        <div className="flex items-center gap-2">
          <span className="text-sm text-gray-600">{t('rowsPerPage')}:</span>
          <select
            value={perPage}
            onChange={(e) => { onPerPageChange(Number(e.target.value)); }}
            className="rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
          >
            {PER_PAGE_OPTIONS.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Navigation */}
      <div className="flex items-center gap-2">
        <span className="text-sm text-gray-600">
          {t('page')} {currentPage} {t('of')} {lastPage}
        </span>
        <div className="flex gap-1">
          <Button variant="secondary" size="sm" onClick={handlePrev} disabled={!hasPrev}>
            <ChevronLeft className="h-4 w-4" />
            <span className="sr-only">{t('previous')}</span>
          </Button>
          <Button variant="secondary" size="sm" onClick={handleNext} disabled={!hasNext}>
            <ChevronRight className="h-4 w-4" />
            <span className="sr-only">{t('next')}</span>
          </Button>
        </div>
      </div>
    </div>
  )
}
