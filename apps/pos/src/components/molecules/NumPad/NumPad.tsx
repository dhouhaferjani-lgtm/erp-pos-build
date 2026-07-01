import { useCallback } from 'react';
import { useTranslation } from 'react-i18next';
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
 * Keys are all neutral surfaces; backspace and clear are differentiated by
 * icon (Delete) and label ("C"), never by ad-hoc semantic color tints.
 * A `000` key is provided because TND is 3-decimal and cashiers type thousands.
 *
 * Layout:
 *   [ 7 ] [ 8 ] [ 9 ]  [ ⌫ ]
 *   [ 4 ] [ 5 ] [ 6 ]  [ C ]
 *   [ 1 ] [ 2 ] [ 3 ]  [ . ]   (decimal; blank when allowDecimal=false)
 *   [ 0 ] [ 00] [000]
 */
export function NumPad({
  value,
  onChange,
  allowDecimal = true,
  className,
}: NumPadProps) {
  const { t } = useTranslation('pos');
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

  // All keys share one neutral surface voice; meaning comes from glyph/label.
  const keyClass =
    'flex items-center justify-center rounded-xl bg-surface-sunken text-xl font-semibold text-ink transition-colors active:bg-border-subtle active:scale-[0.97] select-none min-h-[56px]';

  return (
    <div className={cn('grid grid-cols-4 gap-2', className)}>
      {/* Row 1: 7 8 9 ⌫ */}
      <button type="button" onClick={() => handleKey('7')} className={keyClass}>7</button>
      <button type="button" onClick={() => handleKey('8')} className={keyClass}>8</button>
      <button type="button" onClick={() => handleKey('9')} className={keyClass}>9</button>
      <button
        type="button"
        aria-label={t('numpad.backspace')}
        onClick={() => handleKey('backspace')}
        className={keyClass}
      >
        <Delete className="h-5 w-5" />
      </button>

      {/* Row 2: 4 5 6 C */}
      <button type="button" onClick={() => handleKey('4')} className={keyClass}>4</button>
      <button type="button" onClick={() => handleKey('5')} className={keyClass}>5</button>
      <button type="button" onClick={() => handleKey('6')} className={keyClass}>6</button>
      <button
        type="button"
        aria-label={t('numpad.clear')}
        onClick={() => handleKey('clear')}
        className={keyClass}
      >
        C
      </button>

      {/* Row 3: 1 2 3 . (decimal, or blank when not allowed) */}
      <button type="button" onClick={() => handleKey('1')} className={keyClass}>1</button>
      <button type="button" onClick={() => handleKey('2')} className={keyClass}>2</button>
      <button type="button" onClick={() => handleKey('3')} className={keyClass}>3</button>
      {allowDecimal ? (
        <button
          type="button"
          onClick={() => handleKey('.')}
          disabled={value.includes('.')}
          className={cn(keyClass, 'disabled:text-ink-faint disabled:cursor-not-allowed')}
        >
          .
        </button>
      ) : (
        <div />
      )}

      {/* Row 4: 0 00 000 */}
      <button type="button" onClick={() => handleKey('0')} className={keyClass}>0</button>
      <button type="button" onClick={() => handleKey('00')} className={keyClass}>00</button>
      <button type="button" onClick={() => handleKey('000')} className={keyClass}>000</button>
      {/* Empty cell to complete the 4-col grid */}
      <div />
    </div>
  );
}
