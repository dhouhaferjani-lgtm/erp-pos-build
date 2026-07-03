import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { useIncomeList, usePostIncome } from '../hooks/useIncome'
import { Button } from '@/components/atoms/Button'
import { ListPageLayout } from '@/components/molecules/ListPageLayout'
import { SearchInput } from '@/components/molecules/SearchInput'
import { RequirePermission } from '@/components/auth/RequirePermission'
import { formatCurrency } from '@/lib/format'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import type { IncomeFilters } from '../types'

/**
 * Page: Income list — record and post business income.
 */
export function IncomeListPage() {
  const { t } = useTranslation(['income', 'common'])
  const navigate = useNavigate()
  const [filters, setFilters] = useState<IncomeFilters>({})
  const [searchTerm, setSearchTerm] = useState('')

  const { data, isLoading } = useIncomeList(filters)
  const postIncome = usePostIncome()

  const handleSearch = (value: string) => {
    setSearchTerm(value)
    setFilters((prev) => {
      const { search: _omitted, ...rest } = prev
      return value ? { ...rest, search: value } : rest
    })
  }

  const handlePost = (id: string) => {
    if (confirm(t('income:confirmPost'))) {
      postIncome.mutate(id)
    }
  }

  const rows = data?.data ?? []

  return (
    <ListPageLayout
      title={t('income:title')}
      subtitle={t('income:description')}
      actions={
        <RequirePermission permission="income.create" fallback={null}>
          <Button className="gap-2" onClick={() => { void navigate('/income/new') }}>
            <Plus className="h-4 w-4" />
            {t('income:recordIncome')}
          </Button>
        </RequirePermission>
      }
      filters={
        <SearchInput
          value={searchTerm}
          onChange={handleSearch}
          placeholder={t('income:searchPlaceholder')}
          className="w-full sm:w-72"
        />
      }
    >
      {isLoading ? (
        <div className={`p-6 text-sm ${textColors.tertiary}`}>{t('common:loading')}</div>
      ) : rows.length === 0 ? (
        <div className={`p-8 text-center ${textColors.tertiary}`}>
          <p className="font-medium">{t('income:noIncome')}</p>
          <p className="text-sm">{t('income:noIncomeDescription')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className={`border-b ${borderColors.default} ${textColors.tertiary} text-start`}>
                <th className="px-4 py-2 text-start font-medium">{t('income:form.date')}</th>
                <th className="px-4 py-2 text-start font-medium">{t('income:form.sourceName')}</th>
                <th className="px-4 py-2 text-end font-medium">{t('income:form.amount')}</th>
                <th className="px-4 py-2 text-start font-medium">{t('common:status')}</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {rows.map((income) => (
                <tr key={income.id} className={`border-b ${borderColors.light}`}>
                  <td className="px-4 py-2">{income.document_date}</td>
                  <td className="px-4 py-2">
                    {income.metadata?.source_name || income.document_number}
                  </td>
                  <td className="px-4 py-2 text-end font-medium">
                    {formatCurrency(income.total, { currency: income.currency })}
                  </td>
                  <td className="px-4 py-2">
                    <span className={tokens.badge.base}>
                      {t(`income:status.${income.status === 'posted' ? 'posted' : 'draft'}`)}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-end">
                    {income.status === 'draft' && (
                      <RequirePermission permission="income.post" fallback={null}>
                        <Button
                          variant="secondary"
                          size="sm"
                          onClick={() => { handlePost(income.id) }}
                          disabled={postIncome.isPending}
                        >
                          {t('income:post')}
                        </Button>
                      </RequirePermission>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </ListPageLayout>
  )
}
