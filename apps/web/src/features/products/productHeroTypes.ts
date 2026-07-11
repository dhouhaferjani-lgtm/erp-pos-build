export type ProductHeroEnrichmentState =
  | 'never-submitted'
  | 'pending'
  | 'ready-for-review'
  | 'enriched'
  | 'unavailable'

export interface ProductHeroChip {
  id: string
  label: string
  enriched?: boolean
  href?: string
}
