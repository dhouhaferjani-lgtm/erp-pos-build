import { useState, type FocusEvent } from 'react'

import { QuantityInput, type QuantityInputProps } from './QuantityInput'

export interface DraftQuantityInputProps extends Omit<QuantityInputProps, 'value' | 'onChange'> {
  initialValue: string
  onCommit: (value: string) => void
}

export function DraftQuantityInput({
  initialValue,
  onBlur,
  onCommit,
  onFocus,
  ...props
}: DraftQuantityInputProps) {
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
    <QuantityInput
      {...props}
      value={isFocused ? draft : initialValue}
      onChange={setDraft}
      onBlur={handleBlur}
      onFocus={handleFocus}
    />
  )
}
