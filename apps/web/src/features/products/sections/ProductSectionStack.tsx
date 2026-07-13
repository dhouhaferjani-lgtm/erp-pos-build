import { Fragment, type ReactNode } from 'react'

import { ProductGeneralSection, type ProductGeneralAdapter } from './ProductGeneralSection'
import { ProductHeroSection, type ProductHeroAdapter } from './ProductHeroSection'
import { ProductInventorySection, type ProductInventoryAdapter } from './ProductInventorySection'
import { ProductMediaSection, type ProductMediaAdapter } from './ProductMediaSection'
import { ProductPricingSection, type ProductPricingAdapter } from './ProductPricingSection'
import { PRODUCT_SECTION_KEYS, type ProductSectionKey } from './sectionRegistry'
import { ProductSuppliersSection, type ProductSuppliersAdapter } from './ProductSuppliersSection'
import type { ProductSectionMode } from './types'

type AdapterForMode<T, TMode extends ProductSectionMode> = T extends { mode: TMode } ? T : never

export interface ProductSectionStackAdapters<TMode extends ProductSectionMode> {
  hero: AdapterForMode<ProductHeroAdapter, TMode>
  general: AdapterForMode<ProductGeneralAdapter, TMode>
  pricing: AdapterForMode<ProductPricingAdapter, TMode>
  inventory: AdapterForMode<ProductInventoryAdapter, TMode>
  suppliers: AdapterForMode<ProductSuppliersAdapter, TMode>
  media: AdapterForMode<ProductMediaAdapter, TMode>
}

interface ProductSectionExtensions {
  placement?: ReactNode
  automotive?: ReactNode
  pharmacy?: ReactNode
  loyalty?: ReactNode
  variants?: ReactNode
}

export type ProductSectionStackProps = ProductSectionExtensions & (
  | { mode: 'view'; adapters: ProductSectionStackAdapters<'view'> }
  | { mode: 'edit'; adapters: ProductSectionStackAdapters<'edit'> }
)

function SharedSection({ sectionKey, children }: { sectionKey: ProductSectionKey; children: ReactNode }) {
  return (
    <div className="contents" data-product-section-key={sectionKey}>
      {children}
    </div>
  )
}

function ExtensionSection({ extensionKey, children }: { extensionKey: string; children: ReactNode }) {
  if (children === undefined || children === null || children === false) return null

  return (
    <div className="contents" data-product-extension-key={extensionKey}>
      {children}
    </div>
  )
}

export function ProductSectionStack(props: ProductSectionStackProps) {
  const { adapters } = props
  const sharedSections: Record<ProductSectionKey, ReactNode> = {
    hero: <ProductHeroSection adapter={adapters.hero} />,
    general: <ProductGeneralSection adapter={adapters.general} />,
    pricing: <ProductPricingSection adapter={adapters.pricing} />,
    inventory: <ProductInventorySection adapter={adapters.inventory} />,
    suppliers: <ProductSuppliersSection adapter={adapters.suppliers} />,
    media: <ProductMediaSection adapter={adapters.media} />,
  }

  return (
    <div className="contents" data-product-section-mode={props.mode}>
      {PRODUCT_SECTION_KEYS.map((sectionKey) => (
        <Fragment key={sectionKey}>
          <SharedSection sectionKey={sectionKey}>{sharedSections[sectionKey]}</SharedSection>
          {sectionKey === 'inventory' && (
            <>
              <ExtensionSection extensionKey="placement">{props.placement}</ExtensionSection>
              <ExtensionSection extensionKey="automotive">{props.automotive}</ExtensionSection>
              <ExtensionSection extensionKey="pharmacy">{props.pharmacy}</ExtensionSection>
              <ExtensionSection extensionKey="loyalty">{props.loyalty}</ExtensionSection>
            </>
          )}
          {sectionKey === 'media' && (
            <ExtensionSection extensionKey="variants">{props.variants}</ExtensionSection>
          )}
        </Fragment>
      ))}
    </div>
  )
}
