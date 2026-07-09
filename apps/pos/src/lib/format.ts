export interface PercentFormatOptions {
  maximumFractionDigits?: number;
}

function incrementDecimalDigits(digits: string): string {
  const chars = digits.split('');

  for (let index = chars.length - 1; index >= 0; index -= 1) {
    const digit = chars[index] ?? '0';
    if (digit !== '9') {
      chars[index] = String.fromCharCode(digit.charCodeAt(0) + 1);
      return chars.join('');
    }

    chars[index] = '0';
  }

  return `1${chars.join('')}`;
}

function roundDecimalString(value: string, scale: number): string {
  const trimmed = value.trim();
  const decimalPattern = /^([+-])?(\d*)(?:\.(\d*))?$/;
  const match = decimalPattern.exec(trimmed);

  if (!match || (match[2] === '' && match[3] === '')) {
    return '0';
  }

  const sign = match[1] === '-' ? '-' : '';
  const integerPart = match[2] || '0';
  const fractionPart = match[3] || '';
  const paddedFraction = fractionPart.padEnd(scale + 1, '0');
  const roundingDigit = paddedFraction.charAt(scale);
  const keptFraction = paddedFraction.slice(0, scale);
  const combined = `${integerPart}${keptFraction}` || '0';
  const roundedCombined = roundingDigit >= '5'
    ? incrementDecimalDigits(combined)
    : combined;
  const integerLength = roundedCombined.length - scale;
  const roundedInteger = (integerLength > 0 ? roundedCombined.slice(0, integerLength) : '0')
    .replace(/^0+(?=\d)/, '');
  const roundedFraction = scale > 0
    ? roundedCombined.slice(Math.max(integerLength, 0)).padStart(scale, '0')
    : '';
  const unsigned = roundedFraction
    ? `${roundedInteger}.${roundedFraction}`.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')
    : roundedInteger;

  return unsigned === '0' ? '0' : `${sign}${unsigned}`;
}

export function formatPercent(
  value: string | number | null | undefined,
  options?: PercentFormatOptions,
): string {
  const scale = Math.max(0, options?.maximumFractionDigits ?? 2);
  const rawValue = value === null || value === undefined ? '' : String(value);
  const rounded = roundDecimalString(rawValue, scale);

  return `${rounded}%`;
}
