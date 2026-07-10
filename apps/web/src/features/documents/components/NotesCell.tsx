/**
 * NotesCell
 *
 * Handles per-line additional description (notes field).
 * View state: muted text (clickable) or hover affordance when empty.
 * Edit state: inline textarea with Enter/Shift-Enter/Escape handling.
 */

import { useState, useRef, useCallback, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { textColors, borderColors } from '../../../lib/designTokens'

export type NotesCellProps = {
  value: string | null
  readOnly?: boolean
  className?: string
  valueClassName?: string
  onCommit: (next: string | null) => void
}

export function NotesCell({ value, readOnly = false, className = '', valueClassName, onCommit }: NotesCellProps) {
  const { t } = useTranslation(['documents'])
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(value ?? '')
  const textareaRef = useRef<HTMLTextAreaElement>(null)

  const hasValue = value !== null && value !== ''
  const noteTextClassName = valueClassName ?? `text-xs ${textColors.tertiary}`

  useEffect(() => {
    if (editing && textareaRef.current) {
      textareaRef.current.focus()
    }
  }, [editing])

  const enterEdit = useCallback(() => {
    if (readOnly) return
    setDraft(value ?? '')
    setEditing(true)
  }, [readOnly, value])

  const commit = useCallback(
    (candidate: string) => {
      const trimmed = candidate.trim()
      onCommit(trimmed === '' ? null : trimmed)
      setEditing(false)
    },
    [onCommit]
  )

  const cancel = useCallback(() => {
    setEditing(false)
    setDraft(value ?? '')
  }, [value])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault()
        commit(draft)
      } else if (e.key === 'Escape') {
        e.preventDefault()
        cancel()
      }
    },
    [draft, commit, cancel]
  )

  const handleBlur = useCallback(() => {
    commit(draft)
  }, [draft, commit])

  const handleAffordanceKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLButtonElement>) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        enterEdit()
      }
    },
    [enterEdit]
  )

  if (editing) {
    return (
      <textarea
        ref={textareaRef}
        dir="auto"
        maxLength={1000}
        value={draft}
        onChange={(e) => { setDraft(e.target.value) }}
        onKeyDown={handleKeyDown}
        onBlur={handleBlur}
        aria-label={t('documents:lines.additionalDescription.editAriaLabel')}
        placeholder={t('documents:lines.additionalDescription.placeholder')}
        rows={2}
        className={`w-full rounded border ${borderColors.default} px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 resize-none`}
      />
    )
  }

  return (
    <div data-testid="notes-cell" className={`group ${className}`}>
      {hasValue ? (
        readOnly ? (
          <span className={noteTextClassName}>{value}</span>
        ) : (
          <button
            type="button"
            onClick={enterEdit}
            className={`cursor-pointer text-start hover:${textColors.secondary} ${noteTextClassName}`}
          >
            {value}
          </button>
        )
      ) : (
        !readOnly && (
          <button
            type="button"
            onClick={enterEdit}
            onKeyDown={handleAffordanceKeyDown}
            aria-label={t('documents:lines.additionalDescription.addAriaLabel')}
            className={`flex items-center gap-1 text-xs ${textColors.disabled} opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition-opacity`}
          >
            <Plus className="h-3 w-3" />
            {t('documents:lines.additionalDescription.addLink')}
          </button>
        )
      )}
    </div>
  )
}
