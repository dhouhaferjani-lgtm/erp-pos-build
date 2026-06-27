import React from 'react'
import { useTranslation } from 'react-i18next'
import { tokens, textColors, colors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { CompletenessMeter } from './CompletenessMeter'

/**
 * EditorSection — descriptor for a single navigable section in the product
 * editor. There is NO numeric ordinal marker (the mock dropped 01/02/03).
 */
export interface EditorSection {
  /** DOM element id that scroll-spy observes and click-to-scroll targets. */
  id: string
  /** Human-readable section label (untranslated — caller owns the string). */
  label: string
}

interface SectionNavProps {
  sections: EditorSection[]
  /** Id of the currently active section, or null if none is active yet. */
  activeId: string | null
  /** Called with the section id when the user clicks a nav item. */
  onSelect: (id: string) => void
  /** Overall form completeness 0–100 (raw float; clamped internally). */
  completenessPercent: number
}

/**
 * SectionNav — sticky left-column section navigator, styled as a white card to
 * match the mock:
 * - White card (tokens.card.base): hairline border, radius 14px, padding.
 * - "SECTIONS" rail header: 11px 700 tracked uppercase, gray-400.
 * - Nav items: padding 9px/12px, radius 8px.
 *   - active = bg primary-50 (#E5ECF4) + navy text + weight 600 + an ORANGE
 *     left accent (`inset 2px 0 0` secondary-500) via box-shadow.
 *   - idle   = transparent + gray-600 + weight 500.
 * - Hairline divider, then the green CompletenessMeter.
 */
export function SectionNav({
  sections,
  activeId,
  onSelect,
  completenessPercent,
}: SectionNavProps): React.JSX.Element {
  const { t } = useTranslation('catalog')

  return (
    <nav
      aria-label={t('editor.sections')}
      className={cn(tokens.card.base, 'sticky top-0 flex h-fit w-full flex-col gap-0.5 p-3')}
    >
      {/* Rail header — uppercase 11px 700 tracked, gray-400 */}
      <p
        className={cn(
          'px-3 pb-2 pt-1.5 text-[11px] font-bold uppercase tracking-[0.1em]',
          textColors.disabled,
        )}
      >
        {t('editor.sections')}
      </p>

      {/* Section items */}
      <ul className="flex flex-col gap-0.5" role="list">
        {sections.map((section) => {
          const isActive = section.id === activeId

          return (
            <li key={section.id}>
              <button
                type="button"
                aria-current={isActive ? 'step' : undefined}
                onClick={() => {
                  onSelect(section.id)
                }}
                // Orange left accent on the active item via inset box-shadow
                // (secondary-500 = #EA661A) — the one reserved accent.
                style={isActive ? { boxShadow: 'inset 2px 0 0 var(--color-secondary-500)' } : undefined}
                className={cn(
                  'w-full rounded-lg px-3 py-[9px] text-left text-sm transition-all duration-150',
                  isActive
                    ? cn('bg-primary-50 font-semibold', textColors.primary)
                    : cn('bg-transparent font-medium', textColors.tertiary, textColors.hoverPrimary),
                )}
              >
                {section.label}
              </button>
            </li>
          )
        })}
      </ul>

      {/* Hairline divider */}
      <div className={cn('mx-1 my-2 h-px', colors.neutral[100])} />

      {/* Completeness meter (green) */}
      <CompletenessMeter percent={completenessPercent} />
    </nav>
  )
}
