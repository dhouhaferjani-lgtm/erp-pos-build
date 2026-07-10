import type { ReactNode } from 'react'

import { borderColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface ProductHeroShellProps {
  image: ReactNode
  identity: ReactNode
  enrichment: ReactNode
  helper?: ReactNode
  after?: ReactNode
}

export function withProductHeroImageVariant(url: string | null | undefined): string | null {
  if (url === null || url === undefined || url === '') return null
  if (url.includes('variant=')) return url
  return `${url}${url.includes('?') ? '&' : '?'}variant=md`
}

export function ProductHeroShell({
  image,
  identity,
  enrichment,
  helper,
  after,
}: ProductHeroShellProps) {
  return (
    <section id="section-hero" className={cn('overflow-hidden rounded-xl border', borderColors.light)}>
      <div className={cn('p-4 sm:p-5', tokens.productHero.band)}>
        <div className="grid gap-4 md:grid-cols-[176px_minmax(0,1fr)] md:gap-5">
          <div
            className={cn(
              'group relative h-24 w-24 overflow-hidden rounded-xl border sm:h-44 sm:w-44',
              borderColors.dark,
              tokens.productHero.imageSlot,
              tokens.productHero.imageIcon,
            )}
          >
            {image}
          </div>

          <div className="min-w-0 space-y-3">
            {identity}
            <div className={cn('min-h-10 rounded-lg border p-2.5', borderColors.dark, tokens.productHero.imageSlot)}>
              {enrichment}
            </div>
            <div className="min-h-4">{helper}</div>
          </div>
        </div>
        {after}
      </div>
    </section>
  )
}
