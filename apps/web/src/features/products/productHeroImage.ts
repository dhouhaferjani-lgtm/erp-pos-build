/**
 * Normalise a backend-resolved product image URL for use as an `<img src>`.
 *
 * BUG-005 / RCA A1 — this helper used to append `?variant=md` client-side.
 * The backend now resolves `primary_image_url` through
 * `MediaUrlResolver::forAttachment($primary, 'md')`, which mints a RELATIVE
 * HMAC-signed `media.serve` URL whose signature covers path AND query — any
 * client-side query-string surgery invalidates the HMAC (403), and for
 * ExternalUrl assets it appended a meaningless param to a third-party CDN URL.
 *
 * The variant therefore belongs to the resolver, not the browser. This helper
 * only normalises absent/empty values to `null` so callers can branch on a
 * single nullish check instead of rendering `<img src="">`.
 */
export function productHeroImageSrc(url: string | null | undefined): string | null {
  if (url === null || url === undefined || url === '') return null

  return url
}
