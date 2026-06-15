import { useState, useEffect } from 'react'
import { textColors } from '@/lib/designTokens'

interface KitchenTimerProps {
  startTime: string
  warningMinutes?: number
  overdueMinutes?: number
}

function getElapsed(startTime: string): number {
  return Math.max(0, Date.now() - new Date(startTime).getTime())
}

function formatElapsed(ms: number): string {
  const totalSeconds = Math.floor(ms / 1000)
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60

  if (minutes < 60) {
    return `${String(minutes)}:${String(seconds).padStart(2, '0')}`
  }

  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  return `${String(hours)}:${String(remainingMinutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}

function getTimerColor(ms: number, warningMs: number, overdueMs: number): string {
  // Urgency coding: overdue → error, warning → warning, on-time → success.
  if (ms >= overdueMs) return textColors.error
  if (ms >= warningMs) return textColors.warningDark
  return textColors.success
}

/**
 * Live-updating elapsed timer with green/yellow/red color thresholds.
 */
export function KitchenTimer({
  startTime,
  warningMinutes = 5,
  overdueMinutes = 10,
}: KitchenTimerProps) {
  const [elapsed, setElapsed] = useState(() => getElapsed(startTime))

  useEffect(() => {
    const interval = setInterval(() => {
      setElapsed(getElapsed(startTime))
    }, 1000)

    return () => { clearInterval(interval) }
  }, [startTime])

  const warningMs = warningMinutes * 60 * 1000
  const overdueMs = overdueMinutes * 60 * 1000
  const colorClass = getTimerColor(elapsed, warningMs, overdueMs)

  return (
    <span className={`font-mono text-sm font-bold ${colorClass}`}>
      {formatElapsed(elapsed)}
    </span>
  )
}
