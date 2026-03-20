import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { POSButton } from '../../atoms'
import { X } from 'lucide-react'

export interface CalculatorProps {
  isOpen: boolean
  onClose: () => void
  touchOptimized?: boolean
  className?: string
}

type Operation = '+' | '-' | '×' | '÷' | null

export function Calculator({
  isOpen,
  onClose,
  touchOptimized = false,
  className,
}: CalculatorProps) {
  const { t } = useTranslation(['pos'])
  const [display, setDisplay] = useState('0')
  const [previousValue, setPreviousValue] = useState<number | null>(null)
  const [operation, setOperation] = useState<Operation>(null)
  const [shouldResetDisplay, setShouldResetDisplay] = useState(false)

  useEffect(() => {
    if (!isOpen) {
      // Reset calculator when closed
      setDisplay('0')
      setPreviousValue(null)
      setOperation(null)
      setShouldResetDisplay(false)
    }
  }, [isOpen])

  if (!isOpen) return null

  const handleNumber = (num: string) => {
    if (shouldResetDisplay) {
      setDisplay(num)
      setShouldResetDisplay(false)
    } else {
      setDisplay((prev) => (prev === '0' ? num : prev + num))
    }
  }

  const handleDecimal = () => {
    if (shouldResetDisplay) {
      setDisplay('0.')
      setShouldResetDisplay(false)
    } else if (!display.includes('.')) {
      setDisplay((prev) => prev + '.')
    }
  }

  const handleOperation = (op: Operation) => {
    const current = parseFloat(display)

    if (operation && previousValue !== null && !shouldResetDisplay) {
      // Chain operations - compute the result first
      let result = 0
      switch (operation) {
        case '+':
          result = previousValue + current
          break
        case '-':
          result = previousValue - current
          break
        case '×':
          result = previousValue * current
          break
        case '÷':
          if (current === 0) {
            setDisplay(t('pos:calculator.error'))
            setPreviousValue(null)
            setOperation(null)
            setShouldResetDisplay(true)
            return
          }
          result = previousValue / current
          break
      }
      setDisplay(result.toString())
      setPreviousValue(result)
    } else {
      setPreviousValue(current)
    }

    setOperation(op)
    setShouldResetDisplay(true)
  }

  const handleEquals = () => {
    if (previousValue === null || operation === null) return

    const current = parseFloat(display)
    let result = 0

    switch (operation) {
      case '+':
        result = previousValue + current
        break
      case '-':
        result = previousValue - current
        break
      case '×':
        result = previousValue * current
        break
      case '÷':
        if (current === 0) {
          setDisplay('Error')
          setPreviousValue(null)
          setOperation(null)
          setShouldResetDisplay(true)
          return
        }
        result = previousValue / current
        break
    }

    setDisplay(result.toString())
    setPreviousValue(null)
    setOperation(null)
    setShouldResetDisplay(true)
  }

  const handleClear = () => {
    setDisplay('0')
    setPreviousValue(null)
    setOperation(null)
    setShouldResetDisplay(false)
  }

  const handleBackspace = () => {
    if (display.length === 1 || display === t('pos:calculator.error')) {
      setDisplay('0')
    } else {
      setDisplay((prev) => prev.slice(0, -1))
    }
  }

  const handlePercentage = () => {
    const value = parseFloat(display)
    setDisplay((value / 100).toString())
    setShouldResetDisplay(true)
  }

  const buttonClass = cn(
    'font-semibold',
    touchOptimized ? 'text-2xl' : 'text-xl'
  )

  return (
    <div
      className={cn(
        'fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50',
        className
      )}
      onClick={onClose}
    >
      <div
        className={cn(
          'bg-white rounded-lg shadow-2xl w-full max-w-sm',
          touchOptimized ? 'p-6' : 'p-4'
        )}
        onClick={(e) => { e.stopPropagation(); }}
      >
        {/* Header */}
        <div className="flex items-center justify-between mb-4">
          <h3
            className={cn(
              'font-bold text-gray-900',
              touchOptimized ? 'text-2xl' : 'text-xl'
            )}
          >
            {t('pos:calculator.title')}
          </h3>
          <button
            onClick={onClose}
            className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
            aria-label={t('pos:calculator.close')}
          >
            <X className="w-5 h-5 text-gray-600" />
          </button>
        </div>

        {/* Display */}
        <input
          type="text"
          value={display}
          readOnly
          className={cn(
            'w-full mb-4 px-4 py-3 rounded-lg border-2 border-gray-300',
            'text-end font-mono font-bold bg-gray-50',
            touchOptimized ? 'text-3xl' : 'text-2xl'
          )}
        />

        {/* Button Grid */}
        <div className="grid grid-cols-4 gap-2">
          {/* Row 1: C, ⌫, %, ÷ */}
          <POSButton
            variant="secondary"
            onClick={handleClear}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            C
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={handleBackspace}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            ⌫
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={handlePercentage}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            %
          </POSButton>
          <POSButton
            variant="primary"
            onClick={() => { handleOperation('÷'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            ÷
          </POSButton>

          {/* Row 2: 7, 8, 9, × */}
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('7'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            7
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('8'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            8
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('9'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            9
          </POSButton>
          <POSButton
            variant="primary"
            onClick={() => { handleOperation('×'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            ×
          </POSButton>

          {/* Row 3: 4, 5, 6, - */}
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('4'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            4
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('5'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            5
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('6'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            6
          </POSButton>
          <POSButton
            variant="primary"
            onClick={() => { handleOperation('-'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            -
          </POSButton>

          {/* Row 4: 1, 2, 3, + */}
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('1'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            1
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('2'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            2
          </POSButton>
          <POSButton
            variant="secondary"
            onClick={() => { handleNumber('3'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            3
          </POSButton>
          <POSButton
            variant="primary"
            onClick={() => { handleOperation('+'); }}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            +
          </POSButton>

          {/* Row 5: 0 (span 2), ., = */}
          <div className="col-span-2">
            <POSButton
              variant="secondary"
              onClick={() => { handleNumber('0'); }}
              className={buttonClass}
              fullWidth
              touchOptimized={touchOptimized}
            >
              0
            </POSButton>
          </div>
          <POSButton
            variant="secondary"
            onClick={handleDecimal}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            .
          </POSButton>
          <POSButton
            variant="success"
            onClick={handleEquals}
            className={buttonClass}
            touchOptimized={touchOptimized}
          >
            =
          </POSButton>
        </div>
      </div>
    </div>
  )
}
