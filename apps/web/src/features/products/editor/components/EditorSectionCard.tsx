import React from 'react'
import { tokens, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface EditorSectionCardProps {
  /** DOM id used for scroll-spy observation and nav click-to-scroll. */
  id: string
  /** Section title (already translated by the caller). */
  title: string
  /** Section field content. */
  children: React.ReactNode
  /** Extra classes for the inner content grid wrapper. */
  contentClassName?: string
}

/**
 * EditorSectionCard — a single flat, hairline section card in the product
 * editor's centre column. Matches the mock's `.sec-card` chrome:
 * - radius 14px (--radius-card), hairline border, white bg, ~22px padding.
 * - Header = Montserrat 700 16px navy (--font-display + font-bold + gray-900),
 *   margin-bottom 16px. NO numeric ordinal marker (mock has none).
 * - The `id` is set on the outer element so `useScrollSpy` can observe it and
 *   `SectionNav` can scroll to it.
 */
export function EditorSectionCard({
  id,
  title,
  children,
  contentClassName,
}: EditorSectionCardProps): React.JSX.Element {
  return (
    <section id={id} className={cn(tokens.card.base, 'scroll-mt-4')}>
      <h2
        className={cn(
          'mb-4 font-[family-name:var(--font-display)] text-base font-bold',
          textColors.primary,
        )}
      >
        {title}
      </h2>
      <div className={cn('grid gap-6 sm:grid-cols-2', contentClassName)}>{children}</div>
    </section>
  )
}
