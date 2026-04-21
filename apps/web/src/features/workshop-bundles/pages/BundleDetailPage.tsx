import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { borderColors, textColors, tokens } from '../../../lib/designTokens'
import { useBundle, useDeleteBundleComponent } from '../hooks/useBundles'
import { BundleForm } from '../components/organisms/BundleForm'
import { BundleComponentRow } from '../components/molecules/BundleComponentRow'
import { BundleExpandedPreview } from '../components/organisms/BundleExpandedPreview'
import { BundleComponentFormModal } from '../components/organisms/BundleComponentFormModal'
import { BundleApplicabilityEditor } from '../components/organisms/BundleApplicabilityEditor'
import type { ServiceBundleComponentData } from '../types'

export function BundleDetailPage() {
  const { t } = useTranslation('workshop-bundles')
  const { id } = useParams<{ id: string }>()
  const { data: bundle, isLoading, error } = useBundle(id)
  const deleteComponent = useDeleteBundleComponent(id ?? '')

  const [editing, setEditing] = useState<ServiceBundleComponentData | null>(null)
  const [isCreating, setIsCreating] = useState(false)
  const [deletingId, setDeletingId] = useState<string | null>(null)

  if (isLoading) {
    return <div className={`p-6 text-sm ${textColors.tertiary}`}>{t('detail.loading')}</div>
  }

  if (error !== null || bundle === undefined) {
    return <div className={`p-6 text-sm ${textColors.error}`}>{t('detail.error')}</div>
  }

  const deletingComponent = deletingId !== null
    ? bundle.components.find((c) => c.id === deletingId) ?? null
    : null

  return (
    <div className="mx-auto max-w-5xl space-y-6 p-6">
      <h1 className={`text-2xl font-semibold ${textColors.primary}`}>
        {t('detail.title', { name: bundle.name })}
      </h1>

      <section className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
        <h2 className={`mb-3 text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
          {t('detail.fields')}
        </h2>
        <BundleForm initial={bundle} defaultCurrency={bundle.currency} />
      </section>

      <section className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
        <div className="mb-3 flex items-center justify-between">
          <h2 className={`text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
            {t('detail.components', { count: bundle.components.length })}
          </h2>
          <button
            type="button"
            onClick={() => {
              setIsCreating(true)
            }}
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm} inline-flex items-center gap-1`}
            data-testid="bundle-add-component-btn"
          >
            <Plus className="h-4 w-4" aria-hidden />
            {t('authoring.detail.addComponent')}
          </button>
        </div>
        {bundle.components.length === 0 ? (
          <p className={`text-sm ${textColors.tertiary}`}>{t('detail.emptyComponents')}</p>
        ) : (
          <div>
            {bundle.components.map((component) => (
              <BundleComponentRow
                key={component.id}
                component={component}
                currency={bundle.currency}
                onEdit={(c) => {
                  setEditing(c)
                }}
                onDelete={(c) => {
                  setDeletingId(c.id)
                }}
              />
            ))}
          </div>
        )}
      </section>

      <BundleApplicabilityEditor bundle={bundle} />

      <section>
        <h2 className={`mb-3 text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
          {t('detail.expansionPreview')}
        </h2>
        <BundleExpandedPreview bundleId={bundle.id} currency={bundle.currency} />
      </section>

      {isCreating ? (
        <BundleComponentFormModal
          bundle={bundle}
          onClose={() => {
            setIsCreating(false)
          }}
          onSaved={() => {
            setIsCreating(false)
          }}
        />
      ) : null}

      {editing !== null ? (
        <BundleComponentFormModal
          bundle={bundle}
          component={editing}
          onClose={() => {
            setEditing(null)
          }}
          onSaved={() => {
            setEditing(null)
          }}
        />
      ) : null}

      {deletingComponent !== null ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50"
          data-testid="bundle-delete-confirm"
        >
          <div
            className="relative mx-4 rounded-xl bg-white p-6 shadow-xl"
            style={{ width: '420px', maxWidth: '100%' }}
          >
            <h3 className={`text-base font-semibold ${textColors.primary}`}>
              {t('authoring.deleteConfirm.title')}
            </h3>
            <p className={`mt-2 text-sm ${textColors.secondary}`}>
              {t('authoring.deleteConfirm.body')}
            </p>
            <div className={`mt-4 flex items-center justify-end gap-2 border-t ${borderColors.light} pt-4`}>
              <button
                type="button"
                onClick={() => {
                  setDeletingId(null)
                }}
                className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
              >
                {t('authoring.deleteConfirm.cancel')}
              </button>
              <button
                type="button"
                onClick={() => {
                  deleteComponent.mutate(deletingComponent.id, {
                    onSuccess: () => {
                      setDeletingId(null)
                    },
                  })
                }}
                disabled={deleteComponent.isPending}
                className={`${tokens.button.base} ${tokens.button.danger} ${tokens.button.sizes.sm}`}
                data-testid="bundle-delete-confirm-btn"
              >
                {t('authoring.deleteConfirm.confirm')}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
