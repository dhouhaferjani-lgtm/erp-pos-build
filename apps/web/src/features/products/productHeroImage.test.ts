import { describe, expect, it } from 'vitest'

import { productHeroImageSrc } from './productHeroImage'

/**
 * BUG-005 / RCA A1 — the backend now resolves `primary_image_url` through
 * MediaUrlResolver::forAttachment($primary, 'md'), which mints a RELATIVE
 * HMAC-signed `media.serve` URL. The signature covers path + query, so
 * appending `?variant=md` client-side invalidates the HMAC → 403.
 *
 * The hero helper must therefore hand the backend URL to `<img src>` verbatim
 * and only normalise empty/absent values to null.
 */
describe('productHeroImageSrc', () => {
  it('returns a signed media.serve URL verbatim (no query-string surgery)', () => {
    const signed =
      '/api/v1/media/tenant-uuid/attachment-uuid/serve?expires=1786022473&variant=md&signature=deadbeef'

    expect(productHeroImageSrc(signed)).toBe(signed)
  })

  it('does not append a variant to a signed URL that has no variant param', () => {
    const signed =
      '/api/v1/media/tenant-uuid/attachment-uuid/serve?expires=1786022473&signature=deadbeef'

    expect(productHeroImageSrc(signed)).toBe(signed)
  })

  it('returns an external URL verbatim', () => {
    const external = 'https://cdn.example.com/product-a.jpg'

    expect(productHeroImageSrc(external)).toBe(external)
  })

  it('normalises null, undefined and empty string to null', () => {
    expect(productHeroImageSrc(null)).toBeNull()
    expect(productHeroImageSrc(undefined)).toBeNull()
    expect(productHeroImageSrc('')).toBeNull()
  })
})
