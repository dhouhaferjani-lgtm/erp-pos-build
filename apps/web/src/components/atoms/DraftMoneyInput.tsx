import { useState, type FocusEvent } from 'react'

import { MoneyInput, type MoneyInputProps } from './MoneyInput'

export interface DraftMoneyInputProps extends Omit<MoneyInputProps, 'value' | 'onChange'> {
  initialValue: string
  onCommit: (value: string) => void
}

export function DraftMoneyInput({
  initialValue,
  onBlur,
  onCommit,
  onFocus,
  ...props
}: DraftMoneyInputProps) {
  const [draft, setDraft] = useState(initialValue)
  const [isFocused, setIsFocused] = useState(false)

  function handleFocus(event: FocusEvent<HTMLInputElement>) {
    setDraft(initialValue)
    setIsFocused(true)
    onFocus?.(event)
  }

  function handleBlur(event: FocusEvent<HTMLInputElement>) {
    setIsFocused(false)
    if (draft !== initialValue) {
      onCommit(draft)
    }
    onBlur?.(event)
  }

  return (
    <MoneyInput
      {...props}
      value={isFocused ? draft : initialValue}
      onChange={setDraft}
      onBlur={handleBlur}
      onFocus={handleFocus}
    />
  )
}
