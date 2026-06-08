import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { usePermissions } from '@/hooks/usePermissions'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { getErrorMessage } from '@/lib/api'
import { AttributeForm } from '../components/AttributeForm'
import { AttributeValueEditor } from '../components/AttributeValueEditor'
import { useAttributes, useDeleteAttribute } from '../hooks/useVariants'
import type { ProductAttribute } from '../api/variantApi'

/**
 * Lists product attributes (variant axes) and lets the user create new
 * attributes plus add values to each. Values surface inline through
 * {@link AttributeValueEditor}.
 */
export function AttributeListPage() {
  const { t } = useTranslation()
  const { hasPermission } = usePermissions()
  const { data: attributes, isLoading } = useAttributes()
  const deleteAttribute = useDeleteAttribute()

  const [showForm, setShowForm] = useState(false)
  const [pendingDelete, setPendingDelete] = useState<ProductAttribute | null>(null)

  const canCreate = hasPermission('catalog.attributes.create')
  const canEditValues = hasPermission('catalog.attributes.update')
  const canDelete = hasPermission('catalog.attributes.delete')

  const handleConfirmDelete = async () => {
    if (pendingDelete === null) {
      return
    }
    try {
      await deleteAttribute.mutateAsync(pendingDelete.id)
      toast.success(t('catalog:attributes.deleted'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setPendingDelete(null)
    }
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-2xl font-semibold ${textColors.primary}`}>
            {t('catalog:attributes.title')}
          </h1>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('catalog:attributes.subtitle')}
          </p>
        </div>
        {canCreate ? (
          <button
            type="button"
            onClick={() => { setShowForm((prev) => !prev) }}
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
          >
            <Plus className="mr-1 h-4 w-4" />
            {t('catalog:attributes.add')}
          </button>
        ) : null}
      </div>

      {showForm && canCreate ? (
        <AttributeForm
          onCreated={() => { setShowForm(false) }}
          onCancel={() => { setShowForm(false) }}
        />
      ) : null}

      {isLoading ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('common:loading')}</p>
      ) : (attributes ?? []).length === 0 ? (
        <div className={`${tokens.card.base} text-center`}>
          <p className={`text-sm ${textColors.tertiary}`}>
            {t('catalog:attributes.empty')}
          </p>
        </div>
      ) : (
        <ul className="space-y-4">
          {(attributes ?? []).map((attribute) => (
            <li key={attribute.id} className={tokens.card.base}>
              <div className="flex items-start justify-between">
                <div>
                  <div className="flex items-center gap-2">
                    <h3 className={`text-lg font-medium ${textColors.primary}`}>
                      {attribute.name}
                    </h3>
                    <span className={`font-mono text-sm ${textColors.tertiary}`}>
                      {attribute.code}
                    </span>
                  </div>
                  <div className="mt-1 flex items-center gap-2">
                    <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
                      {t(`catalog:attributes.dataTypes.${attribute.data_type}`)}
                    </span>
                    {attribute.is_variant_axis ? (
                      <span className={`${tokens.badge.base} ${tokens.badge.purple}`}>
                        {t('catalog:attributes.variantAxis')}
                      </span>
                    ) : null}
                  </div>
                </div>
                {canDelete ? (
                  <button
                    type="button"
                    onClick={() => { setPendingDelete(attribute) }}
                    className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm} ${textColors.hoverError}`}
                    aria-label={t('catalog:attributes.delete')}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                ) : null}
              </div>

              <div className={`mt-3 border-t pt-3 ${borderColors.light}`}>
                <AttributeValueEditor attribute={attribute} canEdit={canEditValues} />
              </div>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        isOpen={pendingDelete !== null}
        title={t('catalog:attributes.deleteTitle')}
        message={t('catalog:attributes.deleteMessage', {
          name: pendingDelete?.name ?? '',
        })}
        confirmText={t('catalog:attributes.delete')}
        variant="danger"
        isLoading={deleteAttribute.isPending}
        onConfirm={() => { void handleConfirmDelete() }}
        onClose={() => { setPendingDelete(null) }}
      />
    </div>
  )
}
