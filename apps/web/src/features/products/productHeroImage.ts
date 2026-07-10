export function withProductHeroImageVariant(url: string | null | undefined): string | null {
  if (url === null || url === undefined || url === '') return null
  if (url.includes('variant=')) return url
  return `${url}${url.includes('?') ? '&' : '?'}variant=md`
}
