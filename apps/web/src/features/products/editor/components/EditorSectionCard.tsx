import React from 'react'
import { tokens, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface EditorSectionCardProps {
  /** DOM id used for scroll-spy observation and nav click-to-scroll. */
  id: string
  /** Zero-padded ordinal marker, e.g. "01". */
  marker: string
  /** Section title (already translated by the caller). */
  title: string
  /** Section field content. */
  children: React.ReactNode
  /** Extra classes for the inner content grid wrapper. */
  contentClassName?: string
}

/**
 * EditorSectionCard — a single flat, hairline section card in the product
 * editor's right column.
 *
 * Direction A "Crisp / Operational":
 * - Flat hairline card (`tokens.card.base`), navy structure.
 * - Header row: a muted mono ordinal marker (NOT orange — orange is reserved
 *   for the single active SectionNav accent) + a display-font title.
 * - The `id` is set on the outer element so `useScrollSpy` can observe it and
 *   `SectionNav` can scroll to it.
 */
export function EditorSectionCard({
  id,
  marker,
  title,
  children,
  contentClassName,
}: EditorSectionCardProps): React.JSX.Element {
  return (
    <section id={id} className={cn(tokens.card.base, 'scroll-mt-24')}>
      <div className="mb-4 flex items-center gap-3">
        <span
          className={cn(
            'shrink-0 font-mono text-[11px] uppercase tracking-[0.12em]',
            textColors.disabled,
          )}
        >
          {marker}
        </span>
        <h2 className={cn(tokens.heading.section, 'font-[family-name:var(--font-display)]')}>
          {title}
        </h2>
      </div>
      <div className={cn('grid gap-6 sm:grid-cols-2', contentClassName)}>{children}</div>
    </section>
  )
}
