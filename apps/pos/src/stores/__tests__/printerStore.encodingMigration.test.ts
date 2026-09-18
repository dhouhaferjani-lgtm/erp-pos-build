import { describe, it, expect } from 'vitest';

import { migratePrinterEncoding, migratePrinterState } from '../printerStore';

// ---------------------------------------------------------------------------
// Device recette 2026-09-18 — a staging terminal printed `Re?u`, `Op?rateur`,
// `Qt?`, `Esp?ces`. Its persisted printer `encoding` was `cp437` (the default
// has been `cp1252` since DEV-QA-094, but installs predating it keep whatever
// was stored). CP437 has no `é`/`è`/`ç` cell at all, so it CANNOT print French
// — a terminal sitting on it is moved to CP1252 on first launch.
//
// `cp858` stays untouched: it is a legitimate choice that does carry the
// accents, and the operator can still pick cp437 again by hand afterwards.
// ---------------------------------------------------------------------------
describe('migratePrinterEncoding', () => {
  it('moves a cp437 terminal to cp1252 (cp437 cannot represent French)', () => {
    expect(migratePrinterEncoding('cp437')).toBe('cp1252');
  });

  it('leaves cp858 and cp1252 alone', () => {
    expect(migratePrinterEncoding('cp858')).toBe('cp858');
    expect(migratePrinterEncoding('cp1252')).toBe('cp1252');
  });

  it('collapses an unknown persisted value to the cp1252 default', () => {
    expect(migratePrinterEncoding('nonsense')).toBe('cp1252');
    expect(migratePrinterEncoding('')).toBe('cp1252');
  });
});

describe('migratePrinterState', () => {
  it('rewrites only the encoding and keeps every other persisted setting', () => {
    const migrated = migratePrinterState({
      autoPrint: false,
      printerConfig: { type: 'network', address: '192.168.1.50', port: 9100 },
      settings: {
        paperWidth: '58mm',
        cutMode: 'full',
        copies: 2,
        footerText: 'Merci !',
        encoding: 'cp437',
      },
    });

    expect(migrated.settings.encoding).toBe('cp1252');
    expect(migrated.settings.paperWidth).toBe('58mm');
    expect(migrated.settings.cutMode).toBe('full');
    expect(migrated.settings.copies).toBe(2);
    expect(migrated.settings.footerText).toBe('Merci !');
    expect(migrated.autoPrint).toBe(false);
    expect(migrated.printerConfig).toEqual({
      type: 'network',
      address: '192.168.1.50',
      port: 9100,
    });
  });

  it('survives a persisted payload with no settings block at all', () => {
    const migrated = migratePrinterState({});
    expect(migrated.settings.encoding).toBe('cp1252');
    expect(migrated.settings.paperWidth).toBe('80mm');
  });

  it('survives a null/undefined persisted payload', () => {
    expect(migratePrinterState(null).settings.encoding).toBe('cp1252');
    expect(migratePrinterState(undefined).settings.encoding).toBe('cp1252');
  });
});
