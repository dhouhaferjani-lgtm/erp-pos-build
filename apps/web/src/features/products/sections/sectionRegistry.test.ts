import { describe, expect, expectTypeOf, it } from 'vitest'

import {
  PRODUCT_SECTION_DEFINITIONS,
  PRODUCT_SECTION_KEYS,
  type ProductSectionKey,
  type ProductSectionsAdapter,
  type ProductSectionsEditAdapter,
  type ProductSectionsViewAdapter,
} from './index'

describe('product section registry', () => {
  it('defines the shared view/edit sections once in the locked order', () => {
    expect(PRODUCT_SECTION_KEYS).toEqual([
      'hero',
      'general',
      'pricing',
      'inventory',
      'suppliers',
      'media',
    ])
    expect(PRODUCT_SECTION_DEFINITIONS.map(({ id }) => id)).toEqual([
      'section-hero',
      'section-general',
      'section-pricing',
      'section-inventory',
      'section-suppliers',
      'section-media',
    ])
  })

  it('keeps section keys and adapter modes as closed unions', () => {
    expectTypeOf<ProductSectionKey>().toEqualTypeOf<
      'hero' | 'general' | 'pricing' | 'inventory' | 'suppliers' | 'media'
    >()
    expectTypeOf<ProductSectionsAdapter['mode']>().toEqualTypeOf<'view' | 'edit'>()
    expectTypeOf<ProductSectionsViewAdapter['mode']>().toEqualTypeOf<'view'>()
    expectTypeOf<ProductSectionsEditAdapter['mode']>().toEqualTypeOf<'edit'>()
  })
})
