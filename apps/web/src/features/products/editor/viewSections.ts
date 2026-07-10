import type { ReactNode } from 'react'

export type EditorSectionKey =
  | 'pricing'
  | 'stock-levels'
  | 'automotive'
  | 'metadata'

export interface EditorSectionDef<TContext> {
  id: string
  labelKey: string
  component: EditorSectionKey
  when?: (ctx: TContext) => boolean
  navVisible?: boolean
}

export type EditorSectionRendererRegistry<TContext> = Record<EditorSectionKey, (ctx: TContext) => ReactNode>

export const PRODUCT_DETAIL_SECTIONS: EditorSectionDef<{ isOtospex: boolean; hasAutomotiveData: boolean }>[] = [
  {
    id: 'section-pricing',
    labelKey: 'products.sections.pricing',
    component: 'pricing',
  },
  {
    id: 'section-stock-levels',
    labelKey: 'products.stockLevels.title',
    component: 'stock-levels',
  },
  {
    id: 'section-automotive',
    labelKey: 'products.sections.automotiveInfo',
    component: 'automotive',
    when: (ctx) => ctx.isOtospex && ctx.hasAutomotiveData,
  },
  {
    id: 'section-metadata',
    labelKey: 'products.sections.metadata',
    component: 'metadata',
  },
]
