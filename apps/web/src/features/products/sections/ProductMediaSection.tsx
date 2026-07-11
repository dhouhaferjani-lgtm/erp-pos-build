import { useTranslation } from 'react-i18next'

import { CreateModeImageBuffer } from '../components/CreateModeImageBuffer'
import { ProductImageSection } from '../components/ProductImageSection'
import { EditorSectionCard } from '../editor/components/EditorSectionCard'
import type { ProductSectionsEditAdapter, ProductSectionsViewAdapter } from './types'

export type ProductMediaAdapter =
  | Pick<ProductSectionsViewAdapter, 'mode' | 'product'>
  | Pick<ProductSectionsEditAdapter, 'mode' | 'isEditing' | 'media' | 'productId'>

export function ProductMediaSection({ adapter }: { adapter: ProductMediaAdapter }) {
  const { t } = useTranslation('catalog')

  return (
    <EditorSectionCard
      id="section-media"
      title={t('catalog:editor.sectionLabels.media')}
      contentClassName="sm:grid-cols-1"
    >
      {adapter.mode === 'view' ? (
        <ProductImageSection productId={adapter.product.id} readOnly embedded />
      ) : adapter.isEditing && adapter.productId !== null ? (
        <ProductImageSection productId={adapter.productId} embedded />
      ) : (
        <CreateModeImageBuffer
          bufferedFiles={adapter.media.bufferedImages}
          onFilesChange={adapter.media.onBufferedImagesChange}
        />
      )}
    </EditorSectionCard>
  )
}
