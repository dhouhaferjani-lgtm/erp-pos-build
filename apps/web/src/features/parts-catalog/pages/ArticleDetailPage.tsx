import { useState, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ArticleDetailPanel } from '../components/organisms/ArticleDetailPanel'
import { AddToInventoryModal } from '../components/organisms/AddToInventoryModal'
import { partsCatalogKeys } from '../hooks/usePartsCatalog'
import { usePartsCatalogTenantScope } from '../hooks/usePartsCatalogTenantScope'
import type { EnrichedArticle } from '../types/catalog'

export function ArticleDetailPage() {
  const { articleId } = useParams<{ articleId: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const hasTenantScope = usePartsCatalogTenantScope()

  const [inventoryModalArticle, setInventoryModalArticle] = useState<EnrichedArticle | null>(null)

  const handleBack = useCallback(() => {
    void navigate(-1)
  }, [navigate])

  const handleAddToInventory = useCallback((article: EnrichedArticle) => {
    setInventoryModalArticle(article)
  }, [])

  const handleInventorySuccess = useCallback(() => {
    setInventoryModalArticle(null)
    if (articleId && hasTenantScope) {
      void queryClient.invalidateQueries({
        queryKey: [...partsCatalogKeys.articleDetail(articleId)],
      })
    }
  }, [articleId, hasTenantScope, queryClient])

  if (!articleId) return null

  return (
    <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
      <ArticleDetailPanel
        articleId={articleId}
        onBack={handleBack}
        onAddToInventory={handleAddToInventory}
      />

      <AddToInventoryModal
        isOpen={inventoryModalArticle !== null}
        article={inventoryModalArticle}
        onClose={() => { setInventoryModalArticle(null) }}
        onSuccess={handleInventorySuccess}
      />
    </div>
  )
}
