import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Device-side half of the TN matricule-fiscale convergence guard
 * (research spec 2026-08-23 §3.3).
 *
 * The device seals the same canonical bytes the server verifies, so its
 * `TAX_NUMBER_PATTERNS` TN literal must stay byte-identical to the server's
 * `CountryTaxNumberRules::PATTERNS['TN']`. The server half of this guard lives
 * in `apps/api/tests/Unit/Shared/TunisianMatriculeConvergenceTest.php`.
 */

const ENGINE_PATH = fileURLToPath(new URL('../FiscalEventEngine.ts', import.meta.url));
const SERVER_RULES_PATH = fileURLToPath(
  new URL(
    '../../../../../api/app/Shared/Domain/Validation/CountryTaxNumberRules.php',
    import.meta.url,
  ),
);

/**
 * The TN pattern as it stood BEFORE convergence. Values matching it are already
 * in sealed bytes, so the device must keep accepting every one of them.
 */
const TN_SEALED_LEGACY_PATTERN = /^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/;

function tnLiteralFrom(source: string, pattern: RegExp): string {
  const match = source.match(pattern);
  expect(match, 'TN pattern literal not found').not.toBeNull();
  return (match as RegExpMatchArray)[1];
}

describe('device TN matricule pattern', () => {
  const engineSource = readFileSync(ENGINE_PATH, 'utf8');
  const deviceLiteral = tnLiteralFrom(engineSource, /^\s*TN: (\S+),$/m);
  const deviceTn = new RegExp(deviceLiteral.slice(1, -1));

  it('mirrors the server canonical rule byte-for-byte', () => {
    const serverSource = readFileSync(SERVER_RULES_PATH, 'utf8');
    const serverLiteral = tnLiteralFrom(serverSource, /^\s*'TN' => '(\S+)',$/m);

    // The server literal carries the PHP-only `D` modifier; JS `$` already
    // means end-of-input without the `m` flag, so it has no JS counterpart.
    expect(serverLiteral.endsWith('/D')).toBe(true);
    expect(deviceLiteral).toBe(serverLiteral.slice(0, -1));
  });

  it('accepts the canonical 13-character matricule', () => {
    expect(deviceTn.test('1234567AMN000')).toBe(true);
    expect(deviceTn.test('12345678AMN000')).toBe(true);
  });

  it('remains a superset of the pattern already present in sealed bytes', () => {
    for (const lead of ['0000000', '1234567', '9999999', '00000000', '99999999']) {
      for (let first = 65; first <= 90; first += 1) {
        for (let second = 65; second <= 90; second += 1) {
          const candidate = `${lead}${String.fromCharCode(first)}${String.fromCharCode(second)}000`;
          expect(TN_SEALED_LEGACY_PATTERN.test(candidate)).toBe(true);
          expect(deviceTn.test(candidate)).toBe(true);
        }
      }
    }
  });

  it('still rejects non-canonical shapes', () => {
    expect(deviceTn.test('123456AM000')).toBe(false); // 6 digits
    expect(deviceTn.test('1234567A000')).toBe(false); // 1 letter
    expect(deviceTn.test('1234567ABCD000')).toBe(false); // 4 letters
    expect(deviceTn.test('1234567am000')).toBe(false); // lowercase
    expect(deviceTn.test('1234567AM00')).toBe(false); // 2-digit establishment
  });
});
