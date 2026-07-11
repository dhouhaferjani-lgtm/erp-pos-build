/**
 * DesignationCell
 *
 * Handles both editable and read-only display of a line's designation (description field).
 * View state: designation text + overridden indicator + hover pencil icon.
 * Edit state: inline input, empty validation, reset link.
 */

import { useState, useRef, useCallback, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Pencil } from 'lucide-react'
import { colorClasses, textColors, borderColors, tokens } from '../../../lib/designTokens'

export type DesignationCellProps = {
  value: string
  originalSnapshot: string | null
  productDeleted?: boolean
  readOnly?: boolean
  className?: string
  valueClassName?: string
  onCommit: (next: string) => void
}

export function DesignationCell({
  value,
  originalSnapshot,
  productDeleted = false,
  readOnly = false,
  className = '',
  valueClassName,
  onCommit,
}: DesignationCellProps) {
  const { t } = useTranslation(['documents'])
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(value)
  const [emptyError, setEmptyError] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  // Tracks whether a mousedown on the reset button is in progress, so blur
  // on the input does not prematurely commit before reset can fire.
  const resetPendingRef = useRef(false)

  const isOverridden = originalSnapshot !== null && value !== originalSnapshot

  const enterEdit = useCallback(() => {
    if (readOnly) return
    setDraft(value)
    setEmptyError(false)
    setEditing(true)
  }, [readOnly, value])

  // Select-all on focus
  useEffect(() => {
    if (editing && inputRef.current) {
      inputRef.current.focus()
      inputRef.current.select()
    }
  }, [editing])

  const commit = useCallback(
    (candidate: string) => {
      // If the user is about to click reset, don't commit on blur.
      if (resetPendingRef.current) return
      const trimmed = candidate.trim()
      if (trimmed === '') {
        setEmptyError(true)
        return
      }
      if (trimmed !== value) {
        onCommit(trimmed)
      }
      setEditing(false)
      setEmptyError(false)
    },
    [value, onCommit]
  )

  const cancel = useCallback(() => {
    setEditing(false)
    setEmptyError(false)
    setDraft(value)
  }, [value])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
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

  const handleReset = useCallback(() => {
    resetPendingRef.current = false
    if (productDeleted || originalSnapshot === null) return
    onCommit(originalSnapshot)
    setEditing(false)
    setEmptyError(false)
  }, [productDeleted, originalSnapshot, onCommit])

  const handleResetMouseDown = useCallback(() => {
    // Signal that blur should not commit — reset will handle it.
    resetPendingRef.current = true
  }, [])

  const handlePencilKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLButtonElement>) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        enterEdit()
      }
    },
    [enterEdit]
  )

  const showResetLink =
    originalSnapshot !== null && draft !== originalSnapshot

  if (editing) {
    return (
      <div className="flex flex-col gap-1">
        <input
          ref={inputRef}
          type="text"
          dir="auto"
          maxLength={500}
          value={draft}
          onChange={(e) => {
            setDraft(e.target.value)
            if (emptyError && e.target.value.trim() !== '') setEmptyError(false)
          }}
          onKeyDown={handleKeyDown}
          onBlur={handleBlur}
          className={`w-full rounded border px-2 py-1 text-sm focus:outline-none focus:ring-1 ${
            emptyError
              ? `${colorClasses.borderRed500} ${colorClasses.focusBorderRed500} ${colorClasses.focusRingRed500}`
              : `${borderColors.default} ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`
          }`}
        />
        {emptyError && (
          <span className={`text-xs ${textColors.error}`}>
            {t('documents:lines.designation.emptyHint')}
          </span>
        )}
        {showResetLink && (
          <button
            type="button"
            onMouseDown={handleResetMouseDown}
            onClick={handleReset}
            disabled={productDeleted}
            aria-label={t('documents:lines.designation.resetAriaLabel')}
            title={productDeleted ? t('documents:lines.designation.resetDisabledTooltip') : undefined}
            className={`self-start text-xs ${
              productDeleted
                ? `${textColors.disabled} cursor-not-allowed`
                : `${textColors.brand} hover:underline`
            }`}
          >
            {t('documents:lines.designation.resetLink')}
          </button>
        )}
      </div>
    )
  }

  return (
    <div className={`group flex items-center gap-1.5 ${className}`}>
      <span className={valueClassName ?? `text-sm ${textColors.primary}`}>{value}</span>
      {isOverridden && (
        <span
          role="status"
          aria-label={t('documents:lines.designation.overriddenTooltip', { originalName: originalSnapshot })}
          title={t('documents:lines.designation.overriddenTooltip', { originalName: originalSnapshot })}
          className={tokens.designationOverride.dot}
        />
      )}
      {!readOnly && (
        <button
          type="button"
          onClick={enterEdit}
          onKeyDown={handlePencilKeyDown}
          aria-label={t('documents:lines.designation.editAriaLabel')}
          className={`opacity-0 group-hover:opacity-100 focus-visible:opacity-100 rounded p-0.5 ${textColors.disabled} transition-opacity`}
        >
          <Pencil className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  )
}
