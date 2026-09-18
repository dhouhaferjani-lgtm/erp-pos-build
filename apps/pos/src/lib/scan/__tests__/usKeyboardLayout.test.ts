import { describe, expect, it } from 'vitest';
import { decodeUsKey } from '../usKeyboardLayout';

describe('decodeUsKey', () => {
  it.each([
    ['Digit0', false, '0'], ['Digit0', true, ')'], ['Digit2', true, '@'], ['Digit9', false, '9'],
    ['KeyA', false, 'a'], ['KeyA', true, 'A'], ['KeyQ', true, 'Q'], ['KeyZ', false, 'z'],
    ['Minus', false, '-'], ['Minus', true, '_'], ['Equal', true, '+'],
    ['BracketLeft', false, '['], ['BracketRight', false, ']'], ['Backslash', true, '|'],
    ['Semicolon', true, ':'], ['Quote', false, "'"], ['Backquote', true, '~'],
    ['Comma', true, '<'], ['Period', false, '.'], ['Slash', false, '/'], ['Slash', true, '?'],
    ['Space', false, ' '], ['Space', true, ' '], ['IntlBackslash', false, '\\'],
    ['Numpad0', false, '0'], ['Numpad9', true, '9'], ['NumpadDecimal', false, '.'],
    ['NumpadSubtract', false, '-'], ['NumpadAdd', false, '+'], ['NumpadMultiply', false, '*'], ['NumpadDivide', false, '/'],
  ])('decodes %s shift=%s → %s', (code, shift, expected) => {
    expect(decodeUsKey(code, shift)).toBe(expected);
  });

  it.each(['', 'Unidentified', 'Enter', 'NumpadEnter', 'Tab', 'ShiftLeft', 'Dead', 'F1'])(
    'returns null for non-printable code %s',
    (code) => {
      expect(decodeUsKey(code, false)).toBeNull();
      expect(decodeUsKey(code, true)).toBeNull();
    },
  );
});
