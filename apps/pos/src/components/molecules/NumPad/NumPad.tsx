import { useCallback } from 'react';
import { Delete } from 'lucide-react';
import { cn } from '@/lib/utils';

interface NumPadProps {
  value: string;
  onChange: (value: string) => void;
  /** Allow decimal input. Defaults to true. */
  allowDecimal?: boolean;
  className?: string;
}

/**
 * POS-standard numpad — calculator-style layout (7-8-9 top row).
 *
 * Layout:
 *   [ 7 ] [ 8 ] [ 9 ]  [ ⌫ ]
 *   [ 4 ] [ 5 ] [ 6 ]  [ C ]
 *   [ 1 ] [ 2 ] [ 3 ]  [ 00]
 *   [   0   ]   [ . ]  (or [C] when no decimal)
 */
export function NumPad({
  value,
  onChange,
  allowDecimal = true,
  className,
}: NumPadProps) {
  const handleKey = useCallback(
    (key: string) => {
      if (key === 'backspace') {
        onChange(value.slice(0, -1));
      } else if (key === 'clear') {
        onChange('');
      } else if (key === '.') {
        if (allowDecimal && !value.includes('.')) {
          onChange(value === '' ? '0.' : value + '.');
        }
      } else {
        onChange(value + key);
      }
    },
    [value, onChange, allowDecimal],
  );

  const digitClass =
    'flex items-center justify-center rounded-xl bg-gray-100 text-xl font-semibold text-gray-900 transition-colors active:bg-gray-300 active:scale-[0.97] select-none min-h-[56px]';

  const actionClass =
    'flex items-center justify-center rounded-xl text-base font-semibold transition-colors active:scale-[0.97] select-none min-h-[56px]';

  return (
    <div className={cn('grid grid-cols-4 gap-2', className)}>
      {/* Row 1: 7 8 9 ⌫ */}
      <button type="button" onClick={() => handleKey('7')} className={digitClass}>7</button>
      <button type="button" onClick={() => handleKey('8')} className={digitClass}>8</button>
      <button type="button" onClick={() => handleKey('9')} className={digitClass}>9</button>
      <button
        type="button"
        onClick={() => handleKey('backspace')}
        className={cn(actionClass, 'bg-red-50 text-red-700 active:bg-red-100')}
      >
        <Delete className="h-5 w-5" />
      </button>

      {/* Row 2: 4 5 6 C */}
      <button type="button" onClick={() => handleKey('4')} className={digitClass}>4</button>
      <button type="button" onClick={() => handleKey('5')} className={digitClass}>5</button>
      <button type="button" onClick={() => handleKey('6')} className={digitClass}>6</button>
      <button
        type="button"
        onClick={() => handleKey('clear')}
        className={cn(actionClass, 'bg-orange-50 text-orange-700 active:bg-orange-100')}
      >
        C
      </button>

      {/* Row 3: 1 2 3 00 */}
      <button type="button" onClick={() => handleKey('1')} className={digitClass}>1</button>
      <button type="button" onClick={() => handleKey('2')} className={digitClass}>2</button>
      <button type="button" onClick={() => handleKey('3')} className={digitClass}>3</button>
      <button type="button" onClick={() => handleKey('00')} className={digitClass}>00</button>

      {/* Row 4: 0 (spans 2) + decimal (or C when no decimal) */}
      <button
        type="button"
        onClick={() => handleKey('0')}
        className={cn(digitClass, 'col-span-2')}
      >
        0
      </button>
      {allowDecimal ? (
        <button
          type="button"
          onClick={() => handleKey('.')}
          disabled={value.includes('.')}
          className={cn(digitClass, 'disabled:opacity-50')}
        >
          .
        </button>
      ) : (
        <button
          type="button"
          onClick={() => handleKey('clear')}
          className={cn(actionClass, 'bg-orange-50 text-orange-700 active:bg-orange-100')}
        >
          C
        </button>
      )}
      {/* Empty cell to complete the 4-col grid */}
      <div />
    </div>
  );
}
