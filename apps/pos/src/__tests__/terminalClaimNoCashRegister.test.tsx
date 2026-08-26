/**
 * Campaign lane N-12, gate r1 finding 2 / fiscal E — the terminal-claim refusal
 * `LOCATION_HAS_NO_CASH_REGISTER` must reach the operator translated, and must
 * name a recovery that exists.
 *
 * Before this, nothing in `apps/pos` or `apps/web` referenced the code: the
 * device rethrew and `getErrorMessage()` rendered the server's raw English
 * string, which told the operator to "add a cash repository for the location in
 * Treasury settings" — a screen with no create form that never sends
 * `location_id`. The operator had no way out of a stopped POS.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import en from '@/locales/en/pos.json';
import fr from '@/locales/fr/pos.json';

const KEY = 'locationHasNoCashRegister';

/** [locale, message] — indexed directly so TS strict never sees an index signature. */
const LOCALES: Array<[string, string]> = [
  ['en', en.terminal.locationHasNoCashRegister],
  ['fr', fr.terminal.locationHasNoCashRegister],
];

describe('N-12 — LOCATION_HAS_NO_CASH_REGISTER is a translated, actionable refusal', () => {
  it.each(LOCALES)('every POS locale carries the key (%s)', (_locale, message) => {
    expect(typeof message).toBe('string');
    expect(message.length).toBeGreaterThan(20);
  });

  it('names the recovery that exists, not the Treasury screen that does not', () => {
    // The only in-product path that provisions a location's drawer is saving the
    // location with POS enabled (LocationController's provisioning hook).
    expect(en.terminal.locationHasNoCashRegister).toMatch(/POS enabled/i);
    expect(fr.terminal.locationHasNoCashRegister).toMatch(/point de vente activé/i);
  });

  it('the claim handler maps the code to the key instead of echoing the server string', () => {
    const src = readFileSync(
      resolve(process.cwd(), 'src/pages/TerminalSetupPage.tsx'),
      'utf8',
    );

    expect(src).toContain("err.code === 'LOCATION_HAS_NO_CASH_REGISTER'");
    expect(src).toContain(`t('terminal.${KEY}')`);
    // …and the generic path is still there for every other refusal.
    expect(src).toContain('onError(getErrorMessage(err))');
  });
});
