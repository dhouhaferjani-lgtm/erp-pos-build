/**
 * US-QWERTY physical-position table for keyboard-wedge scanners.
 *
 * A USB scanner programmed for a US keyboard emits HID scan codes; the host OS
 * translates them through ITS layout before the browser sees `e.key`, so on an
 * FR-AZERTY host `0` arrives as `à`. `KeyboardEvent.code` is the layout-independent
 * physical position, so decoding `code` + `shiftKey` through this table recovers
 * what the scanner meant. Used by `scannerStore.keyboardLayout` `'us'` (always) and
 * `'auto'` (only when the received text proves the host layout re-mapped the scan).
 */
type Pair = readonly [unshifted: string, shifted: string];

const US_QWERTY: Readonly<Record<string, Pair>> = {
  Digit1: ['1', '!'], Digit2: ['2', '@'], Digit3: ['3', '#'], Digit4: ['4', '$'], Digit5: ['5', '%'],
  Digit6: ['6', '^'], Digit7: ['7', '&'], Digit8: ['8', '*'], Digit9: ['9', '('], Digit0: ['0', ')'],
  KeyA: ['a', 'A'], KeyB: ['b', 'B'], KeyC: ['c', 'C'], KeyD: ['d', 'D'], KeyE: ['e', 'E'], KeyF: ['f', 'F'],
  KeyG: ['g', 'G'], KeyH: ['h', 'H'], KeyI: ['i', 'I'], KeyJ: ['j', 'J'], KeyK: ['k', 'K'], KeyL: ['l', 'L'],
  KeyM: ['m', 'M'], KeyN: ['n', 'N'], KeyO: ['o', 'O'], KeyP: ['p', 'P'], KeyQ: ['q', 'Q'], KeyR: ['r', 'R'],
  KeyS: ['s', 'S'], KeyT: ['t', 'T'], KeyU: ['u', 'U'], KeyV: ['v', 'V'], KeyW: ['w', 'W'], KeyX: ['x', 'X'],
  KeyY: ['y', 'Y'], KeyZ: ['z', 'Z'],
  Minus: ['-', '_'], Equal: ['=', '+'], BracketLeft: ['[', '{'], BracketRight: [']', '}'],
  Backslash: ['\\', '|'], IntlBackslash: ['\\', '|'], Semicolon: [';', ':'], Quote: ["'", '"'],
  Backquote: ['`', '~'], Comma: [',', '<'], Period: ['.', '>'], Slash: ['/', '?'], Space: [' ', ' '],
  Numpad0: ['0', '0'], Numpad1: ['1', '1'], Numpad2: ['2', '2'], Numpad3: ['3', '3'], Numpad4: ['4', '4'],
  Numpad5: ['5', '5'], Numpad6: ['6', '6'], Numpad7: ['7', '7'], Numpad8: ['8', '8'], Numpad9: ['9', '9'],
  NumpadDecimal: ['.', '.'], NumpadSubtract: ['-', '-'], NumpadAdd: ['+', '+'],
  NumpadMultiply: ['*', '*'], NumpadDivide: ['/', '/'],
};

export function decodeUsKey(code: string, shiftKey: boolean): string | null {
  const pair = Object.prototype.hasOwnProperty.call(US_QWERTY, code) ? US_QWERTY[code] : undefined;
  if (pair === undefined) return null;
  return shiftKey ? pair[1] : pair[0];
}
