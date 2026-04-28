import { useCallback } from 'react';
import { Delete } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getCurrencyDecimals } from '@/lib/currency';

interface CurrencyNumpadProps {
  value: string;
  onChange: (next: string) => void;
  currencyCode: string;
  disabled?: boolean;
  autoFocus?: boolean;
  'aria-label'?: string;
  className?: string;
}

export function CurrencyNumpad({
  value,
  onChange,
  currencyCode,
  disabled = false,
  autoFocus = false,
  'aria-label': ariaLabel,
  className,
}: CurrencyNumpadProps) {
  const maxDecimals = getCurrencyDecimals(currencyCode);

  const appendDigit = useCallback(
    (digit: string) => {
      if (disabled) return;

      const dotIdx = value.indexOf('.');
      if (dotIdx >= 0) {
        const decimals = value.length - dotIdx - 1;
        if (decimals >= maxDecimals) return;
      }

      if (value === '0' && digit !== '.') {
        onChange(digit);
        return;
      }

      onChange(value + digit);
    },
    [value, maxDecimals, disabled, onChange],
  );

  const appendDot = useCallback(() => {
    if (disabled) return;
    if (maxDecimals === 0) return;
    if (value.includes('.')) return;
    onChange((value === '' ? '0' : value) + '.');
  }, [value, maxDecimals, disabled, onChange]);

  const backspace = useCallback(() => {
    if (disabled) return;
    onChange(value.slice(0, -1));
  }, [value, disabled, onChange]);

  const buttonBase = cn(
    'flex h-14 w-full items-center justify-center rounded-md text-xl font-medium transition-colors',
    'bg-gray-50 text-gray-900 hover:bg-gray-100 active:bg-gray-200',
    disabled && 'cursor-not-allowed opacity-50',
  );

  return (
    <div
      aria-label={ariaLabel}
      className={cn('grid grid-cols-3 gap-2', className)}
      role="group"
    >
      {([1, 2, 3, 4, 5, 6, 7, 8, 9] as const).map((d) => (
        <button
          key={d}
          type="button"
          autoFocus={autoFocus && d === 1}
          className={buttonBase}
          disabled={disabled}
          onClick={() => appendDigit(String(d))}
          data-testid={`numpad-digit-${d}`}
        >
          {d}
        </button>
      ))}
      <button
        type="button"
        className={buttonBase}
        disabled={disabled || maxDecimals === 0}
        onClick={appendDot}
        data-testid="numpad-dot"
      >
        .
      </button>
      <button
        type="button"
        className={buttonBase}
        disabled={disabled}
        onClick={() => appendDigit('0')}
        data-testid="numpad-digit-0"
      >
        0
      </button>
      <button
        type="button"
        className={buttonBase}
        disabled={disabled}
        onClick={backspace}
        data-testid="numpad-backspace"
        aria-label="backspace"
      >
        <Delete className="h-5 w-5" />
      </button>
    </div>
  );
}
