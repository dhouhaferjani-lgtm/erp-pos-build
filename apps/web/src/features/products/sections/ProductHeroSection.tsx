import { ProductEditHero } from '../editor/components/ProductEditHero'
import { ProductHero } from '../editor/components/ProductHero'
import type { ProductSectionsEditAdapter, ProductSectionsViewAdapter } from './types'

type ProductHeroEditAdapter = Pick<
  ProductSectionsEditAdapter,
  'media' | 'mode' | 'productId'
> & {
  name: string
  barcode: string
  primaryImageUrl: string | null
  disabled?: boolean
  hero: Omit<ProductSectionsEditAdapter['hero'], 'lookupState'>
}

export type ProductHeroAdapter =
  | Pick<ProductSectionsViewAdapter, 'mode' | 'product'>
  | ProductHeroEditAdapter

export function ProductHeroSection({ adapter }: { adapter: ProductHeroAdapter }) {
  if (adapter.mode === 'view') return <ProductHero product={adapter.product} />

  return (
    <ProductEditHero
      name={adapter.name}
      barcode={adapter.barcode}
      primaryImageUrl={adapter.primaryImageUrl}
      bufferedFiles={adapter.media.bufferedImages}
      onBufferedFilesChange={adapter.media.onBufferedImagesChange}
      enrichmentState={adapter.hero.enrichmentState}
      chips={adapter.hero.chips}
      onBarcodeChange={adapter.hero.onBarcodeChange}
      onNameChange={adapter.hero.onNameChange}
      onProductData={adapter.hero.onProductData}
      onLookupStateChange={adapter.hero.onLookupStateChange}
      onManualRefresh={adapter.hero.onManualRefresh}
      {...(adapter.productId === null ? {} : { productId: adapter.productId })}
      {...(adapter.disabled === undefined ? {} : { disabled: adapter.disabled })}
    />
  )
}
