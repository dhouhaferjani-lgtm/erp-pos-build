import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Plus, Edit, Trash2 } from 'lucide-react'
import { useCategories, useDeleteUnit } from '../hooks/useUnits'
import { AddUnitModal } from '../components/AddUnitModal'
import { UnitDecimalSettings } from '@/features/settings/components/UnitDecimalSettings'
import { Button } from '@/components/atoms/Button/Button'
import { Badge } from '@/components/atoms/Badge/Badge'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import type { Unit } from '../api/uomApi'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function UnitsSettingsPage() {
  const { t } = useTranslation(['common', 'uom'])
  const { data: categories, isLoading, error } = useCategories()
  const deleteMutation = useDeleteUnit()

  const [isAddModalOpen, setIsAddModalOpen] = useState(false)
  const [editingUnit, setEditingUnit] = useState<Unit | undefined>(undefined)
  const [selectedCategoryId, setSelectedCategoryId] = useState<string | undefined>(undefined)
  const [deleteUnit, setDeleteUnit] = useState<Unit | null>(null)

  const handleAddUnit = (categoryId?: string) => {
    setSelectedCategoryId(categoryId)
    setEditingUnit(undefined)
    setIsAddModalOpen(true)
  }

  const handleEditUnit = (unit: Unit) => {
    setEditingUnit(unit)
    setSelectedCategoryId(undefined)
    setIsAddModalOpen(true)
  }

  const handleCloseModal = () => {
    setIsAddModalOpen(false)
    setEditingUnit(undefined)
    setSelectedCategoryId(undefined)
  }

  const handleConfirmDelete = async () => {
    if (!deleteUnit) return

    try {
      await deleteMutation.mutateAsync(deleteUnit.id)
      toast.success(t('uom:unitDeleted'))
      setDeleteUnit(null)
    } catch (error: unknown) {
      const errorMessage = error instanceof Error ? error.message : t('common:common.error')
      toast.error(errorMessage)
      setDeleteUnit(null)
    }
  }

  const handleDeleteClick = (unit: Unit) => {
    if (unit.isSystem) {
      toast.error(t('uom:errors.systemUnit'))
      return
    }
    setDeleteUnit(unit)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Spinner size="lg" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className={`${colorTokens.intent.danger.textSubtle}`}>{t('common:common.error')}</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-3xl font-bold ${colorTokens.text.primary}`}>{t('uom:title')}</PageHeaderTitle>
          <p className={`mt-2 ${colorTokens.text.muted}`}>{t('uom:systemUnitInfo')}</p>
        </div>
        <Button onClick={() => { handleAddUnit(); }}>
          <Plus className="h-4 w-4 mr-2" />
          {t('uom:addUnit')}
        </Button>
      </div>

      {/* Categories and Units */}
      <div className="space-y-6">
        {categories?.map((category) => (
          <div key={category.id} className={`rounded-lg border ${colorTokens.border.subtle} overflow-hidden`}>
            {/* Category Header */}
            <div className={`px-6 py-4 ${colorTokens.surface.page} border-b ${colorTokens.border.subtle} flex items-center justify-between`}>
              <div>
                <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{category.name}</h2>
                {category.description && (
                  <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>{category.description}</p>
                )}
              </div>
              <Button
                onClick={() => { handleAddUnit(category.id); }}
                variant="secondary"
                size="sm"
              >
                <Plus className="h-4 w-4 mr-2" />
                {t('uom:addUnit')}
              </Button>
            </div>

            {/* Units Table */}
            <div className="overflow-x-auto">
              <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
                <thead className={`${colorTokens.surface.page}`}>
                  <tr>
                    <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                      {t('uom:name')}
                    </th>
                    <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                      {t('uom:code')}
                    </th>
                    <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                      {t('uom:symbol')}
                    </th>
                    <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                      {t('uom:conversionFactor')}
                    </th>
                    <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                      {t('common:common.status')}
                    </th>
                    <th className={`px-6 py-3 text-right text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                      {t('common:table.actionsColumn')}
                    </th>
                  </tr>
                </thead>
                <tbody className={`bg-white divide-y ${colorTokens.border.divider}`}>
                  {category.units && category.units.length > 0 ? (
                    category.units.map((unit) => (
                      <tr key={unit.id} className={`hover:${colorTokens.surface.page}`}>
                        <td className={`px-6 py-4 text-sm font-medium ${colorTokens.text.primary}`}>
                          {unit.name}
                        </td>
                        <td className={`px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                          <code className={`text-xs ${colorTokens.surface.muted} px-2 py-1 rounded`}>
                            {unit.code}
                          </code>
                        </td>
                        <td className={`px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                          {unit.symbol}
                        </td>
                        <td className={`px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                          {unit.isBaseUnit ? (
                            <Badge variant="info">{t('uom:isBaseUnit')}</Badge>
                          ) : (
                            `× ${unit.conversionFactor}`
                          )}
                        </td>
                        <td className="px-6 py-4 text-sm">
                          {unit.isSystem ? (
                            <Badge variant="default">{t('uom:isSystem')}</Badge>
                          ) : (
                            <Badge variant="success">{t('common:common.custom')}</Badge>
                          )}
                        </td>
                        <td className="px-6 py-4 text-sm text-right">
                          <div className="flex items-center justify-end gap-2">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => { handleEditUnit(unit); }}
                              disabled={unit.isSystem}
                              aria-label={t('common:actions.edit')}
                            >
                              <Edit className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => { handleDeleteClick(unit); }}
                              disabled={unit.isSystem || deleteMutation.isPending}
                              aria-label={t('common:actions.delete')}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={6} className={`px-6 py-8 text-center ${colorTokens.text.subtle}`}>
                        {t('uom:noUnits')}
                      </td>
                    </tr>
                  )}
                </tbody>
              </DataTable>
            </div>
          </div>
        ))}
      </div>

      {/* Per-unit decimal precision settings */}
      <UnitDecimalSettings />

      {/* Add/Edit Modal */}
      <AddUnitModal
        isOpen={isAddModalOpen}
        onClose={handleCloseModal}
        unit={editingUnit}
        categoryId={selectedCategoryId}
      />

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={!!deleteUnit}
        onClose={() => { setDeleteUnit(null); }}
        onConfirm={handleConfirmDelete}
        title={t('common:common.confirmDelete', { resource: deleteUnit?.name || '' })}
        message={t('uom:errors.unitInUse')}
        confirmText={t('common:actions.delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
