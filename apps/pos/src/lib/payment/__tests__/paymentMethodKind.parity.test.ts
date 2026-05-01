/**
 * B4 audit Minor #1 — PHP↔TS parity test for PaymentInstrumentKind.
 *
 * Reads the PHP enum source via node:fs, parses case values with a regex, and
 * asserts that TS INSTRUMENT_BEARING_METHOD_CODES exactly matches the PHP enum
 * cases (excluding the `none` sentinel). Catches silent drift if a new case is
 * added on one side and not the other.
 *
 * PHP source of truth:
 *   apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';
import { INSTRUMENT_BEARING_METHOD_CODES } from '../paymentMethodKind';

// Path from apps/pos/src/lib/payment/__tests__/ to the worktree root:
// 6 `..` segments → worktree root → then descend into apps/api/...
const PHP_ENUM_PATH = resolve(
  __dirname,
  '../../../../../../apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php',
);

describe('PaymentInstrumentKind PHP↔TS parity', () => {
  it('PHP enum file is reachable (sanity check the path)', () => {
    // Guards against silent drift if the file is moved without updating this test.
    expect(() => readFileSync(PHP_ENUM_PATH, 'utf8')).not.toThrow();
  });

  it('TS INSTRUMENT_BEARING_METHOD_CODES matches PHP enum case values', () => {
    const source = readFileSync(PHP_ENUM_PATH, 'utf8');

    // Match `case <Name> = '<value>';` lines and capture the string value.
    // Filter out the `None = 'none'` sentinel — it is not instrument-bearing.
    const phpValues = Array.from(
      source.matchAll(/case\s+\w+\s*=\s*'([^']+)'\s*;/g),
    )
      .map(([, value]) => value as string)
      .filter((value) => value !== 'none')
      .sort();

    const tsValues = [...INSTRUMENT_BEARING_METHOD_CODES].sort();

    expect(tsValues).toEqual(phpValues);
  });
});
