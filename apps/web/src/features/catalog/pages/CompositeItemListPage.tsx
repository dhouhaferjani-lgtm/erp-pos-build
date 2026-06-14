import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Plus, Upload, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useCompositeItems, useDeleteCompositeItem } from '../hooks/useCompositeItems'
import { useCompanyVerticalLabels } from '../hooks/useVerticalLabels'
import { Button, StatusBadge } from '@/components/atoms'
import {
  DataTable,
  type DataTableColumn,
  ListPageLayout,
} from '@/components/molecules'
import { SearchInput } from '@/components/ui/SearchInput'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { cn } from '@/lib/utils'
import { textColors } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import type { CompositeItemData } from '../types/compositeItem'

export function CompositeItemListPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const navigate = useNavigate()
  const getLabel = useCompanyVerticalLabels()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [itemToDelete, setItemToDelete] = useState<CompositeItemData | null>(null)

  const { hasPermission } = usePermissions()
  const canDelete = hasPermission('composite-items.delete')

  const { data, isLoading } = useCompositeItems({ search: search || undefined, page, per_page: perPage })
  const deleteMutation = useDeleteCompositeItem()
  const items = data?.data ?? []
  const meta = data?.meta

  const handleSearchChange = (value: string) => {
    setSearch(value)
    setPage(1)
  }

  const handleDeleteConfirm = async () => {
    if (!itemToDelete) return
    try {
      await deleteMutation.mutateAsync(itemToDelete.id)
      toast.success(t('catalog:deleteCompositeItemSuccess'))
      setItemToDelete(null)
    } catch (error: unknown) {
      const errorMessage = error instanceof Error ? error.message : String(error)
      toast.error(t('catalog:deleteCompositeItemError', { error: errorMessage }))
    }
  }

  const columns: DataTableColumn<CompositeItemData>[] = [
    {
      key: 'code',
      header: t('catalog:code'),
      render: (item) => (
        <Link
          to={`/catalog/composite-items/${item.id}/edit`}
          className={cn('font-medium', textColors.brand, 'hover:underline')}
        >
          {item.code}
        </Link>
      ),
    },
    {
      key: 'name',
      header: t('catalog:name'),
      render: (item) => item.name,
    },
    {
      key: 'category',
      header: t('catalog:category'),
      render: (item) => (
        <span className={textColors.tertiary}>{item.category_name ?? '-'}</span>
      ),
    },
    {
      key: 'base_price',
      header: t('catalog:basePrice'),
      numeric: true,
      render: (item) => item.base_price,
    },
    {
      key: 'production_type',
      header: t('catalog:productionType'),
      render: (item) => (
        <span className={textColors.tertiary}>
          {t(`catalog:productionTypes.${item.production_type}`)}
        </span>
      ),
    },
    {
      key: 'is_active',
      header: t('catalog:isActive'),
      render: (item) => (
        <StatusBadge tone={item.is_active ? 'success' : 'neutral'}>
          {item.is_active ? t('common:active') : t('common:inactive')}
        </StatusBadge>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('common:table.actionsColumn')}</span>,
      align: 'right',
      render: (item) =>
        canDelete ? (
          <Button
            type="button"
            variant="danger"
            size="sm"
            onClick={() => { setItemToDelete(item) }}
            aria-label={t('common:delete')}
            className="!px-2 !py-1"
          >
            <Trash2 className="h-4 w-4 mr-1" />
            {t('common:delete')}
          </Button>
        ) : null,
    },
  ]

  return (
    <ListPageLayout
      title={getLabel('compositeItems')}
      actions={
        <>
          <Button
            variant="secondary"
            className="gap-1.5"
            onClick={() => { void navigate('/settings/import/wizard/composite_items') }}
          >
            <Upload className="h-4 w-4" />
            {t('catalog:bulkImport')}
          </Button>
          <Button
            className="gap-1.5"
            onClick={() => { void navigate('/catalog/composite-items/new') }}
          >
            <Plus className="h-4 w-4" />
            {t('catalog:createCompositeItem')}
          </Button>
        </>
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
        data={items}
        keyExtractor={(item) => item.id}
        isLoading={isLoading}
        emptyTitle={t('catalog:noCompositeItems')}
      />

      <ConfirmDialog
        isOpen={itemToDelete !== null}
        onClose={() => { setItemToDelete(null) }}
        onConfirm={() => { void handleDeleteConfirm() }}
        title={t('catalog:deleteCompositeItemTitle')}
        message={t('catalog:deleteCompositeItemMessage', { name: itemToDelete?.name ?? '' })}
        confirmText={t('common:confirm')}
        cancelText={t('common:cancel')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </ListPageLayout>
  )
}
