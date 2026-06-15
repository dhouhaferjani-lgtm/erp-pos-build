import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { useModifierGroups } from '../hooks/useModifierGroups'
import { useCompanyVerticalLabels } from '../hooks/useVerticalLabels'
import { Button, StatusBadge } from '@/components/atoms'
import {
  DataTable,
  type DataTableColumn,
  ListPageLayout,
} from '@/components/molecules'
import { SearchInput } from '@/components/ui/SearchInput'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { cn } from '@/lib/utils'
import { textColors } from '@/lib/designTokens'
import type { ModifierGroupData } from '../types/compositeItem'

export function ModifierGroupListPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const navigate = useNavigate()
  const getLabel = useCompanyVerticalLabels()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  const { data, isLoading } = useModifierGroups({ search: search || undefined, page, per_page: perPage })
  const groups = data?.data ?? []
  const meta = data?.meta

  const handleSearchChange = (value: string) => {
    setSearch(value)
    setPage(1)
  }

  const columns: DataTableColumn<ModifierGroupData>[] = [
    {
      key: 'code',
      header: t('catalog:code'),
      render: (group) => (
        <Link
          to={`/catalog/modifier-groups/${group.id}/edit`}
          className={cn('font-medium', textColors.brand, 'hover:underline')}
        >
          {group.code}
        </Link>
      ),
    },
    {
      key: 'name',
      header: t('catalog:name'),
      render: (group) => group.name,
    },
    {
      key: 'selection_type',
      header: t('catalog:selectionType'),
      render: (group) => (
        <span className={textColors.tertiary}>
          {group.selection_type === 'single' ? t('catalog:single') : t('catalog:multiple')}
        </span>
      ),
    },
    {
      key: 'modifiers',
      header: t('catalog:modifiers'),
      numeric: true,
      render: (group) => group.modifiers?.length ?? 0,
    },
    {
      key: 'is_required',
      header: t('catalog:isRequired'),
      render: (group) => (group.is_required ? t('common:yes') : t('common:no')),
    },
    {
      key: 'is_active',
      header: t('catalog:isActive'),
      render: (group) => (
        <StatusBadge tone={group.is_active ? 'success' : 'neutral'}>
          {group.is_active ? t('common:active') : t('common:inactive')}
        </StatusBadge>
      ),
    },
  ]

  return (
    <ListPageLayout
      title={getLabel('modifierGroups')}
      actions={
        <Button
          className="gap-1.5"
          onClick={() => { void navigate('/catalog/modifier-groups/new') }}
        >
          <Plus className="h-4 w-4" />
          {t('catalog:createModifierGroup')}
        </Button>
      }
      filters={
        <SearchInput
          value={search}
          onChange={handleSearchChange}
          placeholder={t('common:search', 'Search')}
          className="w-full sm:w-72"
        />
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
            onPerPageChange={(n) => { setPerPage(n); setPage(1) }}
          />
        ) : undefined
      }
    >
      <DataTable
        columns={columns}
        data={groups}
        keyExtractor={(group) => group.id}
        isLoading={isLoading}
        emptyTitle={t('catalog:noModifierGroups')}
      />
    </ListPageLayout>
  )
}
