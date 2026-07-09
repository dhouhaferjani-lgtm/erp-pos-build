import { describe, it, expect } from 'vitest';

import { migrateDisplayMode, resolveDefaultMode } from '../settingsStore';

// ---------------------------------------------------------------------------
// Task 14 — view-mode widening ('grid'|'visual' → 'vitrine'|'liste'|'tableau')
// + persisted-state migration.
//
// `migrateDisplayMode` is the PURE mapping used by BOTH the zustand-persist
// `migrate` function AND this test. Legacy persisted values must map forward:
//   'grid'   → 'liste'    (the old compact/list card layout is now the touch list)
//   'visual' → 'vitrine'  (the old image cards are now the vitrine)
// New values pass through; anything unrecognised collapses to the default.
// ---------------------------------------------------------------------------
describe('migrateDisplayMode', () => {
  it('maps legacy grid → liste', () => {
    expect(migrateDisplayMode('grid')).toBe('liste');
  });

  it('maps legacy visual → vitrine', () => {
    expect(migrateDisplayMode('visual')).toBe('vitrine');
  });

  it('passes through the new triplet values unchanged', () => {
    expect(migrateDisplayMode('vitrine')).toBe('vitrine');
    expect(migrateDisplayMode('liste')).toBe('liste');
    expect(migrateDisplayMode('tableau')).toBe('tableau');
  });

  it('collapses an unknown value to the default (liste)', () => {
    expect(migrateDisplayMode('nonsense')).toBe('liste');
    expect(migrateDisplayMode('')).toBe('liste');
  });
});

describe('resolveDefaultMode', () => {
  it('never throws and returns a valid triplet value in jsdom/SSR', () => {
    const mode = resolveDefaultMode();
    expect(['vitrine', 'liste', 'tableau']).toContain(mode);
  });
});
