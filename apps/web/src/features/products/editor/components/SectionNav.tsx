import React from 'react'
import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'
import { CompletenessMeter } from './CompletenessMeter'

/**
 * EditorSection — descriptor for a single navigable section in the product
 * editor. Task 1.5 passes an array of these to SectionNav.
 */
export interface EditorSection {
  /** DOM element id that scroll-spy observes and click-to-scroll targets. */
  id: string
  /** Human-readable section label (untranslated — caller owns the string). */
  label: string
  /** Zero-padded ordinal marker, e.g. "01", "02". */
  marker: string
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
 * SectionNav — sticky left-column section navigator for the product editor.
 *
 * Direction A "Crisp / Operational":
 * - Navy structure, one orange accent (secondary-500), hairline/flat styling.
 * - Uppercase 11px tracked "SECTIONS" rail header.
 * - Mono "01/02/03" markers; section labels in UI font.
 * - Active indicator: left border + text in secondary-500 (theme-bridged).
 * - Inactive items: textColors.disabled (text-gray-400 → nearest token).
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
      className="sticky top-6 flex h-fit w-full flex-col gap-1"
    >
      {/* Rail header — uppercase 11px tracked, textColors.disabled */}
      <p className={`mb-3 text-[11px] uppercase tracking-[0.12em] ${textColors.disabled}`}>
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
                className={[
                  'flex w-full items-center gap-2.5 border-l-2 py-1.5 pl-3 pr-2 text-left transition-colors duration-150',
                  isActive
                    ? /* ONE orange accent — theme-bridged secondary-500 */
                      'border-secondary-500 text-secondary-500'
                    : `border-transparent ${textColors.disabled} ${textColors.hoverSecondary}`,
                ].join(' ')}
              >
                {/* Mono ordinal marker */}
                <span className="shrink-0 font-mono text-[11px] uppercase tracking-[0.12em]">
                  {section.marker}
                </span>

                {/* Section label */}
                <span className="text-sm font-medium">{section.label}</span>
              </button>
            </li>
          )
        })}
      </ul>

      {/* Completeness meter at the bottom of the rail */}
      <CompletenessMeter percent={completenessPercent} />
    </nav>
  )
}
