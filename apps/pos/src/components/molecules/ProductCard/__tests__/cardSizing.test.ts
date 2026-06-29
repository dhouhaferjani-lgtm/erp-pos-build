import { describe, it, expect } from 'vitest';
import { getColumns } from '../cardSizing';

// ---------------------------------------------------------------------------
// Task 25 — getColumns density-aware column counts
// ---------------------------------------------------------------------------

describe('getColumns — displayMode × density', () => {
  // The brief specifies large-screen (≥1024px) column counts:
  // visual+comfortable=5, visual+dense=6, grid+comfortable=4, grid+dense=5

  it('visual + comfortable at lg (≥1024) returns 5', () => {
    expect(getColumns('visual', 'comfortable', 1280)).toBe(5);
  });

  it('visual + dense at lg (≥1024) returns 6', () => {
    expect(getColumns('visual', 'dense', 1280)).toBe(6);
  });

  it('grid + comfortable at lg (≥1024) returns 4', () => {
    expect(getColumns('grid', 'comfortable', 1280)).toBe(4);
  });

  it('grid + dense at lg (≥1024) returns 5', () => {
    expect(getColumns('grid', 'dense', 1280)).toBe(5);
  });

  // Responsive step-down at sm breakpoint (640–1023)
  it('visual + comfortable at sm (768) returns 4', () => {
    expect(getColumns('visual', 'comfortable', 768)).toBe(4);
  });

  it('visual + dense at sm (768) returns 5', () => {
    expect(getColumns('visual', 'dense', 768)).toBe(5);
  });

  it('grid + comfortable at sm (768) returns 3', () => {
    expect(getColumns('grid', 'comfortable', 768)).toBe(3);
  });

  it('grid + dense at sm (768) returns 4', () => {
    expect(getColumns('grid', 'dense', 768)).toBe(4);
  });

  // Minimum columns at xs (<640)
  it('visual + comfortable at xs (400) returns 3', () => {
    expect(getColumns('visual', 'comfortable', 400)).toBe(3);
  });

  it('grid + comfortable at xs (400) returns 2', () => {
    expect(getColumns('grid', 'comfortable', 400)).toBe(2);
  });
});
