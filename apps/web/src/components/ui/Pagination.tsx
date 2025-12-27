import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '../atoms/Button/Button'

interface PaginationProps {
  hasPrev: boolean
  hasNext: boolean
  onPrev: () => void
  onNext: () => void
  perPage: number
  onPerPageChange: (perPage: number) => void
  isLoading?: boolean
}

const PER_PAGE_OPTIONS = [10, 25, 50, 100]

export function Pagination({
  hasPrev,
  hasNext,
  onPrev,
  onNext,
  perPage,
  onPerPageChange,
  isLoading = false,
}: PaginationProps) {
  const { t } = useTranslation('common')

  return (
    <div className="flex items-center justify-between px-4 py-3 border-t bg-white">
      <div className="flex items-center gap-2">
        <span className="text-sm text-gray-600">{t('pagination.rowsPerPage')}:</span>
        <select
          value={perPage}
          onChange={(e) => { onPerPageChange(Number(e.target.value)) }}
          disabled={isLoading}
          className="rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {PER_PAGE_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </div>

      <div className="flex items-center gap-2">
        <Button
          variant="secondary"
          size="sm"
          onClick={onPrev}
          disabled={!hasPrev || isLoading}
          className="gap-1"
        >
          <ChevronLeft className="h-4 w-4" />
          {t('pagination.previous')}
        </Button>
        <Button
          variant="secondary"
          size="sm"
          onClick={onNext}
          disabled={!hasNext || isLoading}
          className="gap-1"
        >
          {t('pagination.next')}
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  )
}
