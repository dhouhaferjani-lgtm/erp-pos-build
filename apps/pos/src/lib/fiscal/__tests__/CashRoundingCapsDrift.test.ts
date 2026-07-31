import { describe, expect, it } from 'vitest';

import { V3_DENOMINATION_CAP_BY_SCALE } from '@/lib/fiscal/payloads/SaleReceiptV3Payload';
import { DENOMINATION_CAP_BY_SCALE } from '@/lib/payment/cashRounding';

/**
 * B1 (Lane B, 2026-07-31) — pins BOTH TS denomination-cap tables against the
 * PHP authority, `App\Shared\Domain\CashRoundingCaps::CAPS`
 * (apps/api/app/Shared/Domain/CashRoundingCaps.php).
 *
 * `CashRoundingCaps.php` is READ-ONLY input to this task — its docblock is
 * explicit that the caps are "history-stable": relaxing a cap re-validates
 * already-signed history and tightening one invalidates it, so an existing
 * entry must never be edited, only a new scale added. This test only READS
 * the file.
 *
 * The existing `readPhpNamedConst` helper in `FiscalPayloadKeyDrift.test.ts`
 * cannot parse `CAPS`: it matches `public const NAME = [...]` with
 * `'lowercase_string'` keys via `/'([a-z_][a-z0-9_]*)'/g`, but `CAPS` is
 * `private const CAPS = [0 => '10', 2 => '1.00', 3 => '1.000']` — an
 * `int => numeric-string` map, not a flat list of string keys. Rather than
 * loosen that helper's regex (and risk silently widening what
 * `FiscalPayloadKeyDrift.test.ts` accepts for its OWN key-set pins), this
 * file owns a small parser purpose-built for `int => 'numeric-string'` PHP
 * const maps and is colocated with the drift-gate suite.
 *
 * Three tables must never silently diverge:
 *   1. PHP `CashRoundingCaps::CAPS` (server-side gate, both ends of the
 *      pipeline per its own docblock);
 *   2. TS `V3_DENOMINATION_CAP_BY_SCALE` (`SaleReceiptV3Payload.ts` — the
 *      device's LAST gate before signing);
 *   3. TS `DENOMINATION_CAP_BY_SCALE` (`cashRounding.ts` — the checkout
 *      layer's rounding-step validator).
 *
 * `SaleReceiptV3Payload.test.ts` already pins (2) === (3) directly (a
 * deliberate duplication, per that module's own docblock). This test closes
 * the remaining leg: (2) and (3) each independently pinned against (1), so a
 * drift in EITHER TS table, or in the PHP source, fails here — not silently
 * downstream in a quarantined receipt.
 */

/**
 * Read a `private const <NAME> = [<int> => '<numeric-string>', ...];` PHP
 * array literal and return it as a `Record<number, string>`.
 *
 * Deliberately narrow: matches ONLY unsigned integer keys mapped to a
 * single-quoted numeric-string value — exactly `CashRoundingCaps::CAPS`'s
 * shape. Anything else in the array body is ignored rather than
 * mis-parsed, so a future non-numeric entry would show up as a MISSING key
 * here (test failure) rather than a silently wrong one.
 */
function readPhpIntKeyedStringConst(constName: string): Record<number, string> {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(__dirname, '../../../../../../apps/api/app/Shared/Domain/CashRoundingCaps.php'),
    path.resolve(__dirname, '../../../../../api/app/Shared/Domain/CashRoundingCaps.php'),
  ];
  const phpPath = candidates.find((p) => fs.existsSync(p));
  if (!phpPath) {
    throw new Error(`CashRoundingCaps.php not found at any candidate path: ${candidates.join(', ')}`);
  }
  const src = fs.readFileSync(phpPath, 'utf8');
  const match = src.match(new RegExp(`private const ${constName} = \\[([\\s\\S]*?)\\];`));
  if (!match) {
    throw new Error(`Could not locate 'private const ${constName} = [...]' in ${phpPath}`);
  }
  const body = match[1] ?? '';
  const entries = Array.from(body.matchAll(/(\d+)\s*=>\s*'([0-9]+(?:\.[0-9]+)?)'/g)).map(
    (m) => [Number(m[1]), m[2] as string] as const,
  );
  if (entries.length === 0) {
    throw new Error(`No int => 'numeric-string' entries extracted from ${constName} in ${phpPath}`);
  }
  return Object.fromEntries(entries);
}

describe('Cash-rounding denomination caps — PHP/TS drift gate (B1)', () => {
  it('CashRoundingCaps::CAPS is read as the expected shape (sanity on the parser itself)', () => {
    const phpCaps = readPhpIntKeyedStringConst('CAPS');
    // If this drifts, EVERY assertion below drifts with it — pinned first,
    // on its own, so a parser bug and a real cap change are distinguishable.
    expect(phpCaps).toEqual({ 0: '10', 2: '1.00', 3: '1.000' });
  });

  it('V3_DENOMINATION_CAP_BY_SCALE (SaleReceiptV3Payload.ts) byte-mirrors CashRoundingCaps::CAPS', () => {
    const phpCaps = readPhpIntKeyedStringConst('CAPS');
    expect(V3_DENOMINATION_CAP_BY_SCALE).toEqual(phpCaps);
  });

  it('DENOMINATION_CAP_BY_SCALE (cashRounding.ts) byte-mirrors CashRoundingCaps::CAPS', () => {
    const phpCaps = readPhpIntKeyedStringConst('CAPS');
    expect(DENOMINATION_CAP_BY_SCALE).toEqual(phpCaps);
  });

  it('all three tables have the identical key set (no unlisted-scale drift)', () => {
    const phpCaps = readPhpIntKeyedStringConst('CAPS');
    const phpScales = Object.keys(phpCaps).map(Number).sort();
    expect(Object.keys(V3_DENOMINATION_CAP_BY_SCALE).map(Number).sort()).toEqual(phpScales);
    expect(Object.keys(DENOMINATION_CAP_BY_SCALE).map(Number).sort()).toEqual(phpScales);
  });
});
