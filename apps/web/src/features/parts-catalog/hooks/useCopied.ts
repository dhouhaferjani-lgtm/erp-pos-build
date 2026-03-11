import { useState, useCallback, useRef } from 'react'

export function useCopied(duration = 2000) {
  const [copied, setCopied] = useState(false)
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const copyToClipboard = useCallback(async (text: string) => {
    await navigator.clipboard.writeText(text)
    setCopied(true)
    if (timeoutRef.current) clearTimeout(timeoutRef.current)
    timeoutRef.current = setTimeout(() => { setCopied(false) }, duration)
  }, [duration])

  return { copied, copyToClipboard }
}
