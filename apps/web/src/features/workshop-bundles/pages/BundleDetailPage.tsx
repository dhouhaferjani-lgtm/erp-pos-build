import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { borderColors, textColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
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
          <Button
            type="button"
            variant="primary"
            size="sm"
            onClick={() => {
              setIsCreating(true)
            }}
            className="gap-1"
            data-testid="bundle-add-component-btn"
          >
            <Plus className="h-4 w-4" aria-hidden />
            {t('authoring.detail.addComponent')}
          </Button>
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
        <Modal
          isOpen
          onClose={() => {
            setDeletingId(null)
          }}
          title={t('authoring.deleteConfirm.title')}
          size="sm"
        >
          <ModalContent>
            <div data-testid="bundle-delete-confirm">
              <p className={cn('text-sm', textColors.secondary)}>
                {t('authoring.deleteConfirm.body')}
              </p>
            </div>
          </ModalContent>
          <ModalFooter className={cn('border-t pt-4', borderColors.light)}>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => {
                setDeletingId(null)
              }}
            >
              {t('authoring.deleteConfirm.cancel')}
            </Button>
            <Button
              type="button"
              variant="danger"
              size="sm"
              onClick={() => {
                deleteComponent.mutate(deletingComponent.id, {
                  onSuccess: () => {
                    setDeletingId(null)
                  },
                })
              }}
              disabled={deleteComponent.isPending}
              data-testid="bundle-delete-confirm-btn"
            >
              {t('authoring.deleteConfirm.confirm')}
            </Button>
          </ModalFooter>
        </Modal>
      ) : null}
    </div>
  )
}
