# POS ReceiptDoc — shared builder, Rust encoder, four printed-ticket fixes (Lane E, PR 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the customer-ticket layout out of Rust into one pure TypeScript builder in `packages/shared/src/receipt` that emits a `ReceiptDoc` segment model, make Rust a pure encoder, and fix the four printed-ticket defects (DEV-QA-085 tax-id printed twice, DEV-QA-086 meaningless QR, DEV-QA-087 `é`/`ç` mojibake, DEV-QA-088 `TND` glued left of the amount).

**Architecture:** `apps/pos` builders keep producing `ReceiptData` (moved verbatim into `@autoerp/shared/src/receipt`); `buildReceiptDoc(data, display, print)` ports `format_receipt_with_settings` (`apps/pos/src-tauri/src/printing/receipt_template.rs:302-868`) branch-for-branch into a `ReceiptDoc`; `printReceipt` invokes the new `print_receipt_doc` Tauri command; `apps/pos/src-tauri/src/printing/doc_encoder.rs` maps segments onto the existing `EscPosBuilder` primitives and builds ONE buffer (the Windows transport writes one RAW job, `printing/mod.rs:64-69`). The old `print_receipt` command and `format_receipt_with_settings` are removed so no second print path survives; a **test-only frozen copy** of the old template (`golden_reference.rs`) keeps the golden-bytes regression possible inside a single `cargo test` run.

**Tech Stack:** TypeScript 5.7 (strict), Vitest 3, React 19 (consumer only), Rust 2021 / Tauri 2, `encoding_rs` 0.8, `serde` 1.

**Spec:** [`docs/superpowers/specs/2026-09-17-pos-receipt-preview-design.md`](../specs/2026-09-17-pos-receipt-preview-design.md) — Delivery table, PR 1 row. Phase-1 evidence (every `path:line` below): `docs/sessions/2026-09-17-lane-e/phase1-report.md`. Registry rows: `docs/qa/DEV-QA-registry.md` DEV-QA-085…088 (DEV-QA-089 is out of scope, ticket E-4).

## Amendments (read before any task)

**A1 — 2026-09-17, owner ruling (Dhouha): HT mode prints NO Remise line and derives nothing.** Overrides Task 3 wherever it computes `discount_ht` or divides by `(1 + rate)`:
- `computeHtTotals(vatDetails, decimals)` keeps its name and export but returns `{ subtotal_ht: string } | null`: `subtotal_ht` = `bcadd` over every `VatBreakdownLine` **net base** already printed in the VAT table (the same strings, at the currency scale). No division, no `discount_ht`, no per-rate remise share. Return `null` when the receipt carries no VAT rows (pre-D-1 era) → the builder falls back to TTC layout and sets `doc.fallback = 'subtotal-mode'`.
- HT layout = `Sous-total HT` · VAT table (or aggregate `TVA` line) · `TOTAL TTC`. The TTC layout is unchanged (`Sous-total` · `Remise` · TVA · `TOTAL`).
- Test replaces the division cases: `Σ base + Σ vat == total` on the fixture (exact, from existing strings); HT fallback flag on a receipt with no VAT rows; second location whose bases differ.
- Drop the `discount_ht` label field and the `discountHt` i18n key (fr/en). Keep `subtotalHt` and `totalTtc`.
- Rationale: `discount_allocated` is a TTC share (`FiscalPayloadConstraintValidator.php:1595-1639`); an HT remise would be new printed-money arithmetic, which the owner refused.

## Global Constraints

- **Money and quantities are strings end to end (CLAUDE.md rule 19).** No `Number(...)`, no `parseFloat`, no `+value` anywhere in this PR. `packages/shared/src/receipt` performs **zero arithmetic on money**: every monetary figure it prints arrives already formatted at currency scale from `apps/pos/src/lib/buildReceiptData.ts` (the same boundary that already precomputes `has_tolerance` / `has_cash_rounding` so Rust never parses money).
- **`packages/shared/src/receipt` imports nothing.** No `big.js`, no `i18next`, no `@/…` alias, no Node builtins. It is consumed by `apps/web`, `apps/pos` and its own Vitest run; any dependency would have to be installed three times. Pure string/array code only.
- **The shared source is typechecked under the strictest consumer flags.** `apps/web` imports the `.ts` source through the `@autoerp/shared` alias, so `apps/web/tsconfig.json` applies to it: `exactOptionalPropertyTypes`, `verbatimModuleSyntax`, `erasableSyntaxOnly`, `noPropertyAccessFromIndexSignature`, `noImplicitReturns`. `apps/pos/tsconfig.json` adds `noUncheckedIndexedAccess`. Therefore, in shared code: use `import type` for type-only imports; never write `{ align: undefined }` (build objects with conditional spreads); never use a TS `enum` or a parameter property; guard every array index.
- **No `any` (CLAUDE.md rule 3).** Use `unknown` + type guards where a shape is not known.
- **Every user-facing string goes through `t()` (CLAUDE.md rule 11).** The shared builder never contains French or English copy: all ticket text arrives on `ReceiptData.labels`, filled by `buildReceiptLabels()` (`apps/pos/src/lib/buildReceiptData.ts:557-606`) from `pos:receiptLabel.*`. The English strings in the builder's fallback table are the *existing* Rust defaults being ported (`receipt_template.rs:186-192`), not new copy.
- **The D-1 era branch and `resolveSellerIdentity` are untouched.** `apps/pos/src/lib/buildReceiptData.ts:169-171` (`isPostRemiseReceipt`) and `:217-235` (era-dependent `subtotal`) keep their exact current code; `apps/pos/src/lib/fiscal/sellerIdentity.ts` is not edited in this PR. The tax-id dedup (DEV-QA-085) is **display-only**, applied inside `buildReceiptDoc`, never on the signed seller block. Guarded by `apps/pos/src/lib/fiscal/__tests__/saleReceiptV5CanonicalParity.test.ts`, `apps/pos/src/lib/fiscal/__tests__/saleReceiptV2CanonicalParity.test.ts` and `apps/pos/src/lib/fiscal/payloads/__tests__/SaleReceiptV1V2ByteStability.test.ts` (+ its snapshot `__snapshots__/SaleReceiptV1V2ByteStability.test.ts.snap`) — all three must stay green and unmodified.
- **`cargo` is rationed.** Every `cargo` invocation in this plan is marked **"ONE run, after `sysctl vm.swapusage` < 9000M, orchestrator-approved"**. There is exactly ONE such invocation in the whole plan (Task 10). No other task may run `cargo build`, `cargo test`, `cargo check` or `pnpm tauri`. Rust tasks before Task 10 are written, reviewed and committed **unverified by compilation**, and Task 10 is the gate that proves them.
- **Columns are `32 | 42` only** (`apps/pos/src/lib/printing.ts:436`: `80mm→42`, `58mm→32`).
- **Currency scale comes from the data, never from a literal.** The fixture is TND (3 decimals, `apps/pos/src/lib/currency.ts:11-14`).
- **Commit subjects are `type(scope): summary`** (never `Phase x.y.z:`). Each task ends with a commit step.
- **Branch:** `feat/pos-receipt-preview`, worktree `.worktrees/pos-receipt-preview`, base `origin/dev` `0b20e28dc`. PR → `dev`; Houssam merges. Never commit on `dev` (CLAUDE.md rule 21).

---

### Task 1: Shared receipt package skeleton, types, and `@autoerp/shared` wiring for `apps/pos`

`apps/pos/src` imports `@autoerp/shared` nowhere today and has no alias for it (`apps/pos/vite.config.ts:10-12` aliases only `@`; `apps/pos/tsconfig.json` paths has only `@/*`). `packages/shared/tsconfig.json` only `include`s `types/**/*`, and the package has no test runner. This task makes the package importable and testable before any receipt logic exists.

**Files:**
- Create: `packages/shared/src/receipt/types.ts`
- Create: `packages/shared/src/receipt/index.ts`
- Create: `packages/shared/vitest.config.ts`
- Modify: `packages/shared/package.json:18-24` (add `test` script + `vitest` devDependency)
- Modify: `packages/shared/tsconfig.json:15` (`"include": ["types/**/*"]` → also `src/**/*`)
- Modify: `apps/pos/vite.config.ts:10-13` (alias block)
- Modify: `apps/pos/tsconfig.json:20-22` (`paths`)
- Modify: `apps/pos/vitest.config.ts:4-8` (alias block)
- Test: `packages/shared/src/receipt/__tests__/types.test.ts`

**Interfaces:**
- Consumes: nothing.
- Produces — every later task and PR 2 depend on these exact names:
  - `type ReceiptAlign = 'left' | 'center' | 'right'`
  - `type ReceiptSize = 'normal' | 'double-height' | 'double-width' | 'double'`
  - `type ReceiptColumns = 32 | 42`
  - `type ReceiptCutMode = 'full' | 'partial' | 'none'`
  - `type ReceiptSubtotalMode = 'TTC' | 'HT'`
  - `type ReceiptSegment` (10-member discriminated union on `kind`)
  - `interface ReceiptDoc { version: 1; columns: ReceiptColumns; segments: ReceiptSegment[]; fallback?: 'subtotal-mode' }`
  - `interface ReceiptDisplaySettings { logo: boolean; subtotalMode: ReceiptSubtotalMode; showVatBreakdown: boolean; showFiscalInfo: boolean; showPaymentDetails: boolean; showCustomer: boolean }`
  - `interface ReceiptPrintContext { columns: ReceiptColumns; cutMode: ReceiptCutMode; footerText: string }`
  - Import path for consumers: `@autoerp/shared/src/receipt`

- [ ] **Step 1: Write the failing test**

Create `packages/shared/src/receipt/__tests__/types.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import type { ReceiptDoc, ReceiptSegment } from '../index';

describe('ReceiptDoc type surface', () => {
  it('accepts every segment kind in one document', () => {
    const segments: ReceiptSegment[] = [
      { kind: 'logo' },
      { kind: 'text', text: 'Café Nour', align: 'center', bold: true, size: 'double' },
      { kind: 'two-column', left: 'TOTAL :', right: '10.000 TND', bold: true, size: 'double-height' },
      { kind: 'three-column', left: '7%', middle: '7.647 TND', right: '0.535 TND' },
      { kind: 'separator', char: '=' },
      { kind: 'blank' },
      { kind: 'qr', payload: 'v1:kid1:abc:mac', moduleSize: 4, label: 'Scanner' },
      { kind: 'feed', lines: 4 },
      { kind: 'cut', mode: 'partial' },
      { kind: 'drawer-kick' },
    ];
    const doc: ReceiptDoc = { version: 1, columns: 42, segments };

    expect(doc.segments).toHaveLength(10);
    expect(doc.columns).toBe(42);
    expect(doc.fallback).toBeUndefined();
  });

  it('carries the subtotal-mode fallback marker', () => {
    const doc: ReceiptDoc = { version: 1, columns: 32, segments: [], fallback: 'subtotal-mode' };
    expect(doc.fallback).toBe('subtotal-mode');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `pnpm --filter @autoerp/shared test`
Expected: FAIL — `@autoerp/shared` has no `test` script yet (`ERR_PNPM_NO_SCRIPT` / "Command \"test\" not found").

- [ ] **Step 3: Give `packages/shared` a Vitest runner**

`packages/shared/vitest.config.ts` (new):

```ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/**/*.{test,spec}.ts'],
  },
});
```

In `packages/shared/package.json`, add to `"scripts"` (after `"typecheck"`, line 20):

```json
    "test": "vitest run",
    "test:watch": "vitest"
```

Install the runner (heavier step — run it alone):

```bash
pnpm add -D vitest@^3.2.4 --filter @autoerp/shared
```

In `packages/shared/tsconfig.json` replace line 15:

```json
  "include": ["types/**/*", "src/**/*"],
```

- [ ] **Step 4: Write the types**

`packages/shared/src/receipt/types.ts` (new):

```ts
/**
 * Canonical printed-ticket document (spec 2026-09-17, Lane E).
 *
 * `buildReceiptDoc` is the ONLY producer; the ESC/POS encoder
 * (`apps/pos/src-tauri/src/printing/doc_encoder.rs`) and the web preview
 * (`apps/web/src/features/settings/components/ReceiptPreview.tsx`) are the two
 * consumers. Keeping layout here is what makes the preview faithful by
 * construction instead of by imitation.
 */

/** Horizontal alignment of a text segment. Mirrors ESC a n (escpos.rs:135-139). */
export type ReceiptAlign = 'left' | 'center' | 'right';

/** Character size multiplier. Mirrors GS ! n (escpos.rs:25-40). */
export type ReceiptSize = 'normal' | 'double-height' | 'double-width' | 'double';

/** Printable columns: 42 on 80 mm paper, 32 on 58 mm (printing.ts:436). */
export type ReceiptColumns = 32 | 42;

/** Paper cut behaviour. Mirrors PrintSettings.cut_mode (receipt_template.rs:282-289). */
export type ReceiptCutMode = 'full' | 'partial' | 'none';

/**
 * Whether the ticket's subtotal block is stated tax-inclusive (today's layout)
 * or tax-exclusive. DISPLAY ONLY — amounts, the sealed payload and the fiscal
 * code are untouched (owner ruling 3, 2026-09-17).
 *
 * Mirrors the PHP enum `App\Modules\Company\Domain\Enums\ReceiptSubtotalMode`
 * landing in PR 2. Once `packages/shared/types/generated.d.ts` carries
 * `App.Modules.Company.Domain.Enums.ReceiptSubtotalMode`, that generated type
 * is the source (CLAUDE.md rule 7) and this alias is re-pointed at it.
 */
export type ReceiptSubtotalMode = 'TTC' | 'HT';

/**
 * One printable unit. The encoder maps each kind onto EscPosBuilder
 * primitives; the preview maps each kind onto a monospace grid row.
 *
 * `logo` is a PLACEHOLDER: no raster command exists in escpos.rs (ticket E-1),
 * so the encoder emits nothing for it and the preview draws a labelled box.
 */
export type ReceiptSegment =
  | { kind: 'text'; text: string; align?: ReceiptAlign; bold?: boolean; size?: ReceiptSize }
  | { kind: 'two-column'; left: string; right: string; bold?: boolean; size?: ReceiptSize }
  | { kind: 'three-column'; left: string; middle: string; right: string; bold?: boolean }
  | { kind: 'separator'; char?: string }
  | { kind: 'blank' }
  | { kind: 'feed'; lines: number }
  | { kind: 'cut'; mode: ReceiptCutMode }
  | { kind: 'qr'; payload: string; moduleSize: number; label?: string }
  | { kind: 'logo' }
  | { kind: 'drawer-kick' };

/** A complete ticket, ready to encode or to render. */
export interface ReceiptDoc {
  version: 1;
  columns: ReceiptColumns;
  segments: ReceiptSegment[];
  /**
   * Set to `'subtotal-mode'` when HT was requested but the receipt cannot
   * state it (pre-D-1 ticket with no per-rate remise share): the document
   * falls back to the TTC block for that ticket. Tests assert on this marker.
   */
  fallback?: 'subtotal-mode';
}

/** Company-level display choices. Sourced from GET /company/config (PR 2). */
export interface ReceiptDisplaySettings {
  logo: boolean;
  subtotalMode: ReceiptSubtotalMode;
  showVatBreakdown: boolean;
  showFiscalInfo: boolean;
  showPaymentDetails: boolean;
  showCustomer: boolean;
}

/** Device-level print context. Sourced from the printer store (printerStore.ts:30-36). */
export interface ReceiptPrintContext {
  columns: ReceiptColumns;
  cutMode: ReceiptCutMode;
  footerText: string;
}
```

`packages/shared/src/receipt/index.ts` (new) — mirrors the `inventory-counting` precedent (`packages/shared/src/inventory-counting/index.ts` is a single `export * from './types';`):

```ts
export * from './types';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `pnpm --filter @autoerp/shared test`
Expected: PASS — 2 tests.

Run: `pnpm --filter @autoerp/shared typecheck`
Expected: PASS.

- [ ] **Step 6: Wire the alias into `apps/pos`**

`apps/pos/vite.config.ts` — replace the `resolve.alias` block at lines 10-13:

```ts
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@autoerp/shared': path.resolve(__dirname, '../../packages/shared'),
    },
  },
```

`apps/pos/tsconfig.json` — replace the `paths` block at lines 20-22:

```json
    "paths": {
      "@/*": ["src/*"],
      "@autoerp/shared/*": ["../../packages/shared/*"]
    }
```

`apps/pos/vitest.config.ts` — replace the `resolve.alias` block at lines 4-8:

```ts
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@autoerp/shared': path.resolve(__dirname, '../../packages/shared'),
    },
  },
```

(`apps/web` already has both: `apps/web/vite.config.ts:14`, `apps/web/vitest.config.ts:22`, `apps/web/tsconfig.json:47`.)

- [ ] **Step 7: Prove the alias resolves from `apps/pos`**

Create `apps/pos/src/lib/__tests__/sharedAlias.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import type { ReceiptDoc } from '@autoerp/shared/src/receipt';

describe('@autoerp/shared alias in apps/pos', () => {
  it('resolves the receipt document type', () => {
    const doc: ReceiptDoc = { version: 1, columns: 42, segments: [{ kind: 'blank' }] };
    expect(doc.segments[0]?.kind).toBe('blank');
  });
});
```

Run: `pnpm --filter @autoerp/pos vitest run src/lib/__tests__/sharedAlias.test.ts`
Expected: PASS.

Run: `pnpm --filter @autoerp/pos typecheck`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add packages/shared/src/receipt packages/shared/vitest.config.ts packages/shared/package.json \
        packages/shared/tsconfig.json apps/pos/vite.config.ts apps/pos/tsconfig.json \
        apps/pos/vitest.config.ts apps/pos/src/lib/__tests__/sharedAlias.test.ts pnpm-lock.yaml
git commit -m "feat(shared-receipt): receipt segment types + @autoerp/shared wiring for apps/pos"
```

---

### Task 2: `formatReceiptMoney` and the `padColumns` parity helpers

Fixes **DEV-QA-088**. Today every monetary cell is `format!("{}{}", data.currency_symbol, amount)` at 17 sites (`receipt_template.rs:520, 525, 530, 545, 564, 581, 591, 601, 611, 646, 647, 654, 672, 681, 696, 704, 724`) — code first, no space — and the symbol itself comes from `Intl.NumberFormat('en', …)` hardcoded at `apps/pos/src/lib/buildReceiptData.ts:50-60`. The placement rule already exists in `apps/web/src/lib/format.ts:98-108` (`currencyAppearsBeforeNumber`) and `:134-136`; this task ports it to a helper that operates on an **already-formatted decimal string** and never parses.

`padColumns` mirrors `EscPosBuilder::two_column` / `three_column` (`apps/pos/src-tauri/src/printing/escpos.rs:213-244` and `:250-271`) so the web preview pads exactly like the printer. The Rust side keeps its own implementation (the encoded width is what matters — see the doc comment at `escpos.rs:209-212`); this helper is the TS twin, pinned to the Rust behaviour by the parity table copied from `escpos.rs:552-594`.

**Files:**
- Create: `packages/shared/src/receipt/money.ts`
- Create: `packages/shared/src/receipt/padColumns.ts`
- Modify: `packages/shared/src/receipt/index.ts:1`
- Test: `packages/shared/src/receipt/__tests__/money.test.ts`
- Test: `packages/shared/src/receipt/__tests__/padColumns.test.ts`

**Interfaces:**
- Consumes: `ReceiptColumns`, `ReceiptSegment` (Task 1).
- Produces:
  - `formatReceiptMoney(amount: string, currency: string, locale: string): string`
  - `currencyAppearsBeforeNumber(locale: string, currency: string): boolean`
  - `padTwoColumns(left: string, right: string, columns: number): string`
  - `padThreeColumns(left: string, middle: string, right: string, columns: number): string`
  - `padColumns(segment: ReceiptSegment, columns: number): string | null`

- [ ] **Step 1: Write the failing tests**

`packages/shared/src/receipt/__tests__/money.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { currencyAppearsBeforeNumber, formatReceiptMoney } from '../money';

describe('formatReceiptMoney', () => {
  it('suffixes the code with a space for fr-TN (the Tunisian terminal locale)', () => {
    expect(formatReceiptMoney('11.000', 'TND', 'fr-TN')).toBe('11.000 TND');
  });

  it('suffixes the code with a space for fr', () => {
    expect(formatReceiptMoney('11.000', 'TND', 'fr')).toBe('11.000 TND');
    expect(formatReceiptMoney('9.175', 'EUR', 'fr')).toBe('9.175 EUR');
  });

  it('prefixes the code with a space where the locale puts it first', () => {
    expect(formatReceiptMoney('11.000', 'USD', 'en')).toBe('USD 11.000');
  });

  it('keeps a leading sign attached to the number, never to the code', () => {
    expect(formatReceiptMoney('-1.000', 'TND', 'fr')).toBe('-1.000 TND');
    expect(formatReceiptMoney('+2.000', 'TND', 'fr')).toBe('+2.000 TND');
    expect(formatReceiptMoney('-1.000', 'USD', 'en')).toBe('USD -1.000');
  });

  it('returns the amount untouched when there is no currency code (Z-report header)', () => {
    expect(formatReceiptMoney('0.000', '', 'fr')).toBe('0.000');
  });

  it('never parses: a value beyond IEEE-754 exactness round-trips verbatim', () => {
    expect(formatReceiptMoney('9007199254740993.123', 'TND', 'fr')).toBe('9007199254740993.123 TND');
  });

  it('falls back to the suffix form when the locale or currency is unusable', () => {
    expect(formatReceiptMoney('1.000', 'ZZZ', 'not-a-locale')).toBe('1.000 ZZZ');
  });
});

describe('currencyAppearsBeforeNumber', () => {
  it('is false for fr / fr-TN and true for en', () => {
    expect(currencyAppearsBeforeNumber('fr', 'TND')).toBe(false);
    expect(currencyAppearsBeforeNumber('fr-TN', 'TND')).toBe(false);
    expect(currencyAppearsBeforeNumber('en', 'USD')).toBe(true);
  });
});
```

`packages/shared/src/receipt/__tests__/padColumns.test.ts` — the parity table is copied case-for-case from the Rust assertions at `apps/pos/src-tauri/src/printing/escpos.rs:552-594` (`test_two_column_accented_french_alignment`, `test_three_column_accented_alignment`) plus `:427-439` (`test_two_column_formatting`):

```ts
import { describe, expect, it } from 'vitest';
import { padColumns, padThreeColumns, padTwoColumns } from '../padColumns';

describe('padTwoColumns — parity with EscPosBuilder::two_column (escpos.rs:213-244)', () => {
  it('pads to exactly the column width (escpos.rs:427-439)', () => {
    const line = padTwoColumns('Item', '10.00', 42);
    expect(line).toHaveLength(42);
    expect(line).toBe('Item'.padEnd(42 - '10.00'.length, ' ') + '10.00');
  });

  it('measures accented text in CHARACTERS, not UTF-8 bytes (escpos.rs:552-577)', () => {
    const accented = padTwoColumns('Reçu :', '12345', 42);
    const ascii = padTwoColumns('Recu :', '12345', 42);
    expect(accented).toHaveLength(42);
    expect(accented.length).toBe(ascii.length);
  });

  it('truncates the LEFT cell when the pair overflows, keeping the right cell whole', () => {
    const line = padTwoColumns('A very long product designation that overflows', '1234.56', 32);
    expect(line).toHaveLength(32);
    expect(line.endsWith('1234.56')).toBe(true);
  });

  it('truncates the right cell too when it alone exceeds the width', () => {
    const line = padTwoColumns('', '123456789012345678901234567890123456', 32);
    expect(line).toHaveLength(32);
  });
});

describe('padThreeColumns — parity with EscPosBuilder::three_column (escpos.rs:250-271)', () => {
  it('pads accented three-column text to exactly the column width (escpos.rs:579-594)', () => {
    expect(padThreeColumns('Réf', 'Désignation', 'Prix', 42)).toHaveLength(42);
  });

  it('distributes the slack left-biased, remainder to the right gap', () => {
    // 42 - (1 + 1 + 1) = 39 slack -> left gap 19, right gap 20.
    expect(padThreeColumns('a', 'b', 'c', 42)).toBe('a' + ' '.repeat(19) + 'b' + ' '.repeat(20) + 'c');
  });

  it('falls back to two columns with middle+right merged when the row overflows', () => {
    const merged = padTwoColumns('left-cell-is-wide', 'mid right', 32);
    expect(padThreeColumns('left-cell-is-wide', 'mid', 'right', 32)).toBe(merged);
  });
});

describe('padColumns', () => {
  it('dispatches on the segment kind and returns null for non-column segments', () => {
    expect(padColumns({ kind: 'two-column', left: 'TOTAL :', right: '10.000 TND' }, 42)).toHaveLength(42);
    expect(padColumns({ kind: 'three-column', left: '7%', middle: '7.647', right: '0.535' }, 42)).toHaveLength(42);
    expect(padColumns({ kind: 'blank' }, 42)).toBeNull();
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `pnpm --filter @autoerp/shared test`
Expected: FAIL — `Cannot find module '../money'` and `'../padColumns'`.

- [ ] **Step 3: Write `money.ts`**

```ts
/**
 * Locale-correct placement of a currency CODE beside an already-formatted
 * decimal string. Ported from apps/web/src/lib/format.ts:98-108,134-136.
 *
 * This module NEVER parses (CLAUDE.md rule 19): `amount` arrives at currency
 * scale from the POS boundary and is concatenated verbatim, sign included.
 * It replaces the `'en'`-hardcoded getCurrencySymbol at
 * apps/pos/src/lib/buildReceiptData.ts:50-60 and the 17
 * `format!("{}{}", currency_symbol, …)` sites in receipt_template.rs
 * (DEV-QA-088).
 */

/**
 * True when the locale renders the currency code BEFORE the number
 * (e.g. `en` + USD), false when it renders it after (e.g. `fr` / `fr-TN`).
 * Falls back to `false` (suffix) when Intl rejects the locale or the code.
 */
export function currencyAppearsBeforeNumber(locale: string, currency: string): boolean {
  try {
    const parts = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      currencyDisplay: 'code',
    }).formatToParts(0);
    const currencyIndex = parts.findIndex((part) => part.type === 'currency');
    const numberIndex = parts.findIndex((part) => part.type === 'integer');
    return currencyIndex !== -1 && numberIndex !== -1 && currencyIndex < numberIndex;
  } catch {
    return false;
  }
}

/**
 * Place `currency` beside `amount` with a separating space.
 *
 * @param amount   Decimal string ALREADY at currency scale. May carry a
 *                 leading `-` or `+`; the sign stays glued to the number.
 * @param currency ISO code (e.g. `TND`). Empty string => the amount is
 *                 returned untouched (Z-report header passes no code).
 * @param locale   UI locale (e.g. `fr`, `fr-TN`, `en`).
 */
export function formatReceiptMoney(amount: string, currency: string, locale: string): string {
  if (currency === '') {
    return amount;
  }
  return currencyAppearsBeforeNumber(locale, currency)
    ? `${currency} ${amount}`
    : `${amount} ${currency}`;
}
```

- [ ] **Step 4: Write `padColumns.ts`**

```ts
import type { ReceiptSegment } from './types';

/**
 * TypeScript twin of EscPosBuilder::two_column / ::three_column
 * (apps/pos/src-tauri/src/printing/escpos.rs:213-244, :250-271).
 *
 * Width is measured in CHARACTERS, not UTF-8 bytes: the printer receives
 * single-byte code-page text, so `ç` is one column even though it is two
 * bytes in UTF-8 (escpos.rs doc comment :204-212). `[...s].length` counts
 * code points, which is the same measure Rust's `.chars().count()` uses.
 *
 * The Rust encoder keeps its own copy — the ENCODED width is what reaches the
 * printer. This helper exists so the web preview pads by the identical rule;
 * `__tests__/padColumns.test.ts` copies the Rust assertion table
 * (escpos.rs:552-594) to keep the two from drifting.
 */

function charCount(value: string): number {
  return [...value].length;
}

function takeChars(value: string, count: number): string {
  return [...value].slice(0, Math.max(0, count)).join('');
}

/** Left cell flush left, right cell flush right, padded to `columns`. */
export function padTwoColumns(left: string, right: string, columns: number): string {
  const leftLen = charCount(left);
  const rightLen = charCount(right);

  if (leftLen + rightLen >= columns) {
    const maxLeft = columns > rightLen + 1 ? columns - rightLen - 1 : columns;
    const truncatedLeft = takeChars(left, Math.min(leftLen, maxLeft));
    const remaining = Math.max(0, columns - charCount(truncatedLeft));
    const paddedRight =
      remaining >= rightLen
        ? ' '.repeat(remaining - rightLen) + right
        : takeChars(right, remaining);
    return truncatedLeft + paddedRight;
  }

  return left + ' '.repeat(columns - leftLen - rightLen) + right;
}

/**
 * Three cells spread across `columns`; slack is split left-biased with the
 * remainder given to the right gap. Overflow falls back to two columns with
 * middle and right merged by a single space — exactly escpos.rs:255-258.
 */
export function padThreeColumns(
  left: string,
  middle: string,
  right: string,
  columns: number,
): string {
  const total = charCount(left) + charCount(middle) + charCount(right);

  if (total >= columns) {
    return padTwoColumns(left, `${middle} ${right}`, columns);
  }

  const slack = columns - total;
  const leftPad = Math.floor(slack / 2);
  const rightPad = slack - leftPad;
  return left + ' '.repeat(leftPad) + middle + ' '.repeat(rightPad) + right;
}

/**
 * Render a column segment to its padded row. Returns `null` for every other
 * segment kind, so the preview can use one call site.
 */
export function padColumns(segment: ReceiptSegment, columns: number): string | null {
  if (segment.kind === 'two-column') {
    return padTwoColumns(segment.left, segment.right, columns);
  }
  if (segment.kind === 'three-column') {
    return padThreeColumns(segment.left, segment.middle, segment.right, columns);
  }
  return null;
}
```

- [ ] **Step 5: Re-export from the package entry point**

`packages/shared/src/receipt/index.ts`:

```ts
export * from './types';
export * from './money';
export * from './padColumns';
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `pnpm --filter @autoerp/shared test`
Expected: PASS — 13 tests, 0 failures.

- [ ] **Step 7: Commit**

```bash
git add packages/shared/src/receipt
git commit -m "fix(pos-receipt DEV-QA-088): locale-correct formatReceiptMoney + padColumns parity helpers"
```

---

### Task 3: Move `ReceiptData` into the package, add `currency_code` / `locale` / `ht_totals`, ship the fixture

`ReceiptData` and its nested shapes live at `apps/pos/src/lib/printing.ts:18-223` today (hand-rolled, mirrored by a second hand-rolled copy in `receipt_template.rs:18-224`). They move into the package **unchanged except for three deliberate edits**, and `printing.ts` re-exports them so all six builders and every call site keep compiling.

The three edits:
1. `currency_symbol: string` → `currency_code: string` + `locale: string`. The symbol was produced by `getCurrencySymbol` with a hardcoded `'en'` (`buildReceiptData.ts:50-60`) and concatenated by Rust; `buildReceiptDoc` now calls `formatReceiptMoney(amount, currency_code, locale)` instead (DEV-QA-088). **`getCurrencySymbol` is NOT deleted** — `buildVoucherTicketData` (`buildReceiptData.ts:664,679`) still needs it, and the voucher ticket keeps its own Rust layout until ticket E-2 (spec §4.4).
2. `ht_totals?: ReceiptHtTotals | null`. HT mode needs `Σ` per-rate **pre-remise** HT bases and `Σ` per-rate HT remise shares. The sealed payload carries only `discount_allocated`, which is the rate group's share of the **TTC** remise (proved by `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1595-1639`: `Σ discount_allocated == transaction_discount_amount`). Deriving the HT share needs a division, and the shared package does **no** arithmetic (Global Constraints), so the POS boundary precomputes it — the same discipline as `has_tolerance` / `has_cash_rounding` (`printing.ts:158-172`).
3. Four new optional label fields: `qr_scan_label`, `subtotal_ht`, `discount_ht`, `total_ttc`.

**Files:**
- Create: `packages/shared/src/receipt/receiptData.ts`
- Create: `packages/shared/src/receipt/fixtures/sampleReceipt.ts`
- Modify: `packages/shared/src/receipt/index.ts:1-3`
- Modify: `apps/pos/src/lib/printing.ts:18-223` (delete the local declarations, re-export from the package), `:241` + `:274` (`buildZReceiptData` input/output), `:369` (`VoucherTicketData.currency_symbol` — untouched, it belongs to the voucher path)
- Modify: `apps/pos/src/lib/buildReceiptData.ts:134,244` · `:295,363` · `:413,436` · `:481,518` · `:730,759` (five `ReceiptData` builders), `:557-606` (`buildReceiptLabels`)
- Modify: `apps/pos/src/components/Header.tsx:647`
- Modify: `apps/pos/src/lib/__tests__/printing.test.ts:12,38,53`
- Test: `packages/shared/src/receipt/__tests__/sampleReceipt.test.ts`
- Test: `apps/pos/src/lib/__tests__/buildReceiptData.htTotals.test.ts`

**Interfaces:**
- Consumes: `formatReceiptMoney` (Task 2).
- Produces:
  - `interface ReceiptHtTotals { subtotal_ht: string; discount_ht: string }`
  - `ReceiptData` with `currency_code: string`, `locale: string`, `ht_totals?: ReceiptHtTotals | null` and **no** `currency_symbol`
  - `CompanyInfo`, `ReceiptLine`, `ModifierLine`, `VatBreakdownLine`, `PaymentLine`, `ReceiptLabels`, `ZReceiptCashCountRow` — all re-exported from `apps/pos/src/lib/printing.ts` under their current names
  - `ReceiptLabels` gains `qr_scan_label?: string`, `subtotal_ht?: string`, `discount_ht?: string`, `total_ttc?: string`
  - `const sampleReceipt: ReceiptData` at `@autoerp/shared/src/receipt/fixtures/sampleReceipt`
  - `computeHtTotals(vatDetails, decimals)` (module-private in `buildReceiptData.ts`, exported for its test)

- [ ] **Step 1: Write the failing tests**

`packages/shared/src/receipt/__tests__/sampleReceipt.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { sampleReceipt } from '../fixtures/sampleReceipt';

describe('sampleReceipt fixture', () => {
  it('is the Phase-1 Café Nour ticket: TN, tax_id == vat_number, TND at 3 decimals', () => {
    expect(sampleReceipt.company.name).toBe('Café Nour');
    expect(sampleReceipt.company.country).toBe('TN');
    expect(sampleReceipt.company.tax_id).toBe('1234567/A/M/000');
    expect(sampleReceipt.company.vat_number).toBe('1234567/A/M/000');
    expect(sampleReceipt.currency_code).toBe('TND');
    expect(sampleReceipt.locale).toBe('fr');
  });

  it('carries the two accented lines the encoding fix is judged on', () => {
    expect(sampleReceipt.lines.map((line) => line.name)).toEqual(['Café crème', 'Garçon']);
  });

  it('is arithmetically coherent in TTC: subtotal - discount == total', () => {
    expect(sampleReceipt.subtotal).toBe('11.000');
    expect(sampleReceipt.discount_amount).toBe('1.000');
    expect(sampleReceipt.total).toBe('10.000');
  });

  it('is arithmetically coherent in HT: subtotal_ht - discount_ht == sum of sealed bases', () => {
    const bases = sampleReceipt.vat_breakdown.map((row) => row.taxable);
    expect(bases).toEqual(['7.647', '1.528']);
    expect(sampleReceipt.ht_totals?.subtotal_ht).toBe('10.092');
    expect(sampleReceipt.ht_totals?.discount_ht).toBe('0.917');
    // 10.092 - 0.917 == 7.647 + 1.528 == 9.175, and 9.175 + 0.825 == 10.000.
  });

  it('has exactly one QR token and a fiscal hash (the two QR sources of DEV-QA-086)', () => {
    expect(sampleReceipt.qr_token).toBe('v1:kid1:0199a0f3-5b1c-7c42-9f0e-2d7a1b3c4d5e:9f3a7c2e');
    expect(sampleReceipt.fiscal_hash).toHaveLength(64);
  });
});
```

`apps/pos/src/lib/__tests__/buildReceiptData.htTotals.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { computeHtTotals } from '../buildReceiptData';

describe('computeHtTotals', () => {
  it('derives per-rate HT bases and HT remise shares from the sealed TTC allocation', () => {
    const totals = computeHtTotals(
      [
        { tax_rate: '7', net_amount: '7.647', vat_amount: '0.535', gross_amount: '8.182', discount_allocated: '0.818' },
        { tax_rate: '19', net_amount: '1.528', vat_amount: '0.290', gross_amount: '1.818', discount_allocated: '0.182' },
      ],
      3,
    );
    expect(totals).toEqual({ subtotal_ht: '10.092', discount_ht: '0.917' });
  });

  it('keeps the identity subtotal_ht - discount_ht == sum(net_amount) exactly', () => {
    const totals = computeHtTotals(
      [{ tax_rate: '19', net_amount: '1.528', vat_amount: '0.290', gross_amount: '1.818', discount_allocated: '0.182' }],
      3,
    );
    expect(totals).not.toBeNull();
    // 1.681 - 0.153 == 1.528
    expect(totals?.subtotal_ht).toBe('1.681');
    expect(totals?.discount_ht).toBe('0.153');
  });

  it('states HT with a zero remise when the ticket carries no discount', () => {
    expect(
      computeHtTotals(
        [{ tax_rate: '7', net_amount: '9.000', vat_amount: '0.630', gross_amount: '9.630', discount_allocated: '0' }],
        3,
      ),
    ).toEqual({ subtotal_ht: '9.000', discount_ht: '0.000' });
  });

  it('returns null on a pre-D-1 receipt (no per-rate remise share) so HT falls back to TTC', () => {
    expect(
      computeHtTotals(
        [{ tax_rate: '7', net_amount: '9.000', vat_amount: '0.630', gross_amount: '9.630', discount_allocated: null }],
        3,
      ),
    ).toBeNull();
  });

  it('returns null when the breakdown is empty (Z-report, account payment)', () => {
    expect(computeHtTotals([], 3)).toBeNull();
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `pnpm --filter @autoerp/shared test` → FAIL (`Cannot find module '../fixtures/sampleReceipt'`).
Run: `pnpm --filter @autoerp/pos vitest run src/lib/__tests__/buildReceiptData.htTotals.test.ts` → FAIL (`computeHtTotals` is not exported).

- [ ] **Step 3: Move the input shape into the package**

Create `packages/shared/src/receipt/receiptData.ts` by moving `apps/pos/src/lib/printing.ts:18-223` **verbatim**, with the three edits. The full file:

```ts
/**
 * Input shape of the printed ticket — moved verbatim from
 * apps/pos/src/lib/printing.ts:18-223 (Lane E, 2026-09-17) so that the web
 * preview and the POS device share ONE definition.
 *
 * Every monetary field is a decimal string ALREADY at currency scale; the
 * builder never parses one (CLAUDE.md rule 19).
 */

export interface CompanyInfo {
  name: string;
  address_line1: string;
  address_line2: string | null;
  city: string;
  postal_code: string;
  country: string;
  tax_id: string;
  phone: string | null;
  /**
   * Establishment VAT number — printed under the tax id when the terminal's
   * location carries a complete fiscal identity (spec 2026-06-11 §4.6).
   * Display-only; never part of the signed seller block. Suppressed by
   * buildReceiptDoc when it equals `tax_id` (DEV-QA-085).
   */
  vat_number?: string | null;
  /** Pre-formatted legal identifier lines (e.g. "SIRET: 552…"). Display-only. */
  legal_identifier_lines?: string[] | null;
}

export interface ModifierLine {
  name: string;
  price: string;
}

export interface ReceiptLine {
  name: string;
  quantity: string;
  unit_price: string;
  line_total: string;
  modifiers: ModifierLine[] | null;
  discount: string | null;
}

export interface VatBreakdownLine {
  rate: string;
  taxable: string;
  tax: string;
}

export interface PaymentLine {
  method: string;
  amount: string;
}

/**
 * Tax-exclusive restatement of the totals block, PRECOMPUTED at the POS
 * boundary (apps/pos/src/lib/buildReceiptData.ts `computeHtTotals`).
 *
 * The sealed payload only carries `discount_allocated`, the rate group's share
 * of the TTC remise (FiscalPayloadConstraintValidator.php:1595-1639 asserts
 * `Σ discount_allocated == transaction_discount_amount`). Turning that into an
 * HT share is a division, and the shared package performs no arithmetic — so
 * the division happens once, on the device, with big.js.
 *
 * Invariant, exact by construction: `subtotal_ht − discount_ht == Σ taxable`.
 * Absent/null on pre-D-1 receipts, whose per-rate remise share was never
 * sealed; buildReceiptDoc then falls back to the TTC block and marks
 * `doc.fallback = 'subtotal-mode'`.
 */
export interface ReceiptHtTotals {
  subtotal_ht: string;
  discount_ht: string;
}

export interface ReceiptLabels {
  receipt?: string;
  date?: string;
  terminal?: string;
  operator?: string;
  customer?: string;
  item?: string;
  qty?: string;
  amount?: string;
  subtotal?: string;
  discount?: string;
  tax?: string;
  total?: string;
  payments?: string;
  change_due?: string;
  /** Label for the SIGNED cash-rounding line in the totals block. */
  rounding?: string;
  /** Label for the tolerance write-off line (distinct from the rounding line). */
  tolerance?: string;
  vat_rate?: string;
  taxable?: string;
  tax_col?: string;
  thank_you?: string;
  tax_id?: string;
  /** Label for the establishment VAT-number header line (e.g. "VAT:" / "TVA :"). */
  vat_number?: string;
  tel?: string;
  cash_count_section_title?: string;
  cash_count_total_variance?: string;
  cash_count_approved_by?: string;
  cash_count_reason?: string;
  cash_count_col_tender?: string;
  cash_count_col_expected?: string;
  cash_count_col_actual?: string;
  cash_count_col_variance?: string;
  /** Refund receipt header label (REMBOURSEMENT / REFUND / AVOIR). */
  refund_header?: string;
  /** "Original ticket:" label printed on refund receipts. */
  original_ticket?: string;
  /** "Scan original ticket:" label printed above the original-receipt QR re-print. */
  original_qr_label?: string;
  account_payment_header?: string;
  balance_before?: string;
  balance_after?: string;
  stale_balance?: string;
  business_date?: string;
  terminal_id?: string;
  shift_id?: string;
  training?: string;
  customer_account?: string;
  customer_phone?: string;
  /** Customer-facing caption above the return/exchange QR (DEV-QA-086). */
  qr_scan_label?: string;
  /** "Sous-total HT" — subtotal line in HT display mode. */
  subtotal_ht?: string;
  /** "Remise HT" — remise line in HT display mode. */
  discount_ht?: string;
  /** "TOTAL TTC" — total line in HT display mode (owner ruling 7). */
  total_ttc?: string;
}

export interface ZReceiptCashCountRow {
  code: string;
  name: string;
  expected: string;
  actual: string;
  variance: string;
  direction: 'over' | 'under' | 'balanced';
}

export interface ReceiptData {
  company: CompanyInfo;
  receipt_number: string;
  date_time: string;
  terminal_name: string;
  operator_name: string;
  lines: ReceiptLine[];
  subtotal: string;
  discount_amount: string;
  tax_amount: string;
  total: string;
  /**
   * ISO currency code (e.g. `TND`). Empty string on the Z-report header, which
   * prints no money. Replaces the former `currency_symbol`, which was resolved
   * with a hardcoded `'en'` locale and glued to the left of every amount
   * (DEV-QA-088).
   */
  currency_code: string;
  /** UI locale (e.g. `fr`, `fr-TN`) that decides currency placement. */
  locale: string;
  vat_breakdown: VatBreakdownLine[];
  payments: PaymentLine[];
  change_due: string;
  /** Cash-sale tolerance write-off in customer-facing currency. Null when not applied. */
  tolerance_writeoff?: string | null;
  /** Precomputed: true when `tolerance_writeoff` is a positive amount. */
  has_tolerance?: boolean;
  /** Signed cash-rounding adjustment. Null when the sale was not rounded. */
  cash_rounding_adjustment?: string | null;
  /** Precomputed: true when `cash_rounding_adjustment` is non-zero (may be negative). */
  has_cash_rounding?: boolean;
  /** Tax-exclusive restatement of the totals block. Null on pre-D-1 receipts. */
  ht_totals?: ReceiptHtTotals | null;
  fiscal_hash: string | null;
  fiscal_signature: string | null;
  customer_name: string | null;
  notes: string | null;
  labels?: ReceiptLabels;
  /** Visibility flags — all default to true when absent (backward compatible). */
  show_vat_breakdown?: boolean;
  show_fiscal_info?: boolean;
  show_payment_details?: boolean;
  show_customer?: boolean;
  /** When true a bold centred DUPLICATA banner is printed. */
  is_reprint?: boolean;
  /** Z-report cash-count block. */
  cash_counts?: ZReceiptCashCountRow[];
  manager_name?: string | null;
  variance_reason?: string | null;
  variance_severity?: string | null;
  aggregate_variance?: string | null;
  /** Signed QR token (`v:kid:receipt_uuid:mac`) for THIS receipt. */
  qr_token?: string | null;
  receipt_kind?: 'sale' | 'refund' | 'account_payment' | 'account_charge';
  original_receipt_number?: string | null;
  original_receipt_qr_token?: string | null;
  account_balance_before?: string | null;
  account_balance_after?: string | null;
  account_snapshot_stale?: boolean;
  business_date?: string | null;
  terminal_id?: string | null;
  shift_id?: string | null;
  training_flag?: boolean;
  customer_account_id?: string | null;
  customer_phone?: string | null;
}
```

Add to `packages/shared/src/receipt/index.ts`:

```ts
export * from './receiptData';
```

- [ ] **Step 4: Re-export from `apps/pos/src/lib/printing.ts`**

Delete lines 18-223 of `apps/pos/src/lib/printing.ts` (the local `CompanyInfo` … `ReceiptData` declarations — keep `PrinterInfo` at `:11-16`, `VoucherTicketLabels` at `:117-131` and everything from `:225` on) and put in their place:

```ts
export type {
  CompanyInfo,
  ModifierLine,
  PaymentLine,
  ReceiptData,
  ReceiptHtTotals,
  ReceiptLabels,
  ReceiptLine,
  VatBreakdownLine,
  ZReceiptCashCountRow,
} from '@autoerp/shared/src/receipt';
```

`VoucherTicketLabels` (`:117-131`) and `VoucherTicketData` (`:355-380`, including its `currency_symbol` at `:369`) stay exactly as they are — the voucher ticket keeps its own Rust layout until ticket E-2.

In `buildZReceiptData`, rename the input field at `:241` and its use at `:274`:

```ts
  /** ISO currency code; '' on a Z header, which prints no money. */
  currencyCode: string;
  /** UI locale that decides currency placement. */
  locale: string;
```

```ts
    currency_code: input.currencyCode,
    locale: input.locale,
    ht_totals: null,
```

- [ ] **Step 5: Update the five `ReceiptData` builders**

In `apps/pos/src/lib/buildReceiptData.ts`, at each of the five sites replace the `const currencySymbol = getCurrencySymbol(<code>)` line with nothing and the `currency_symbol: currencySymbol,` line with the code + locale pair. Exact replacements:

| Builder | delete | replace `currency_symbol: currencySymbol,` with |
|---|---|---|
| `buildEscPosReceiptData` (`:134`, `:244`) | `const currencySymbol = getCurrencySymbol(receipt.currency);` | `currency_code: receipt.currency,`<br>`locale: i18next.language,`<br>`ht_totals: computeHtTotals(receipt.vat_details, decimals),` |
| `buildEscPosFromOfflineReceipt` (`:295`, `:363`) | `const currencySymbol = getCurrencySymbol(result.currency);` | `currency_code: result.currency,`<br>`locale,`<br>`ht_totals: computeHtTotals(result.vatBreakdown ?? [], decimals),` |
| `buildEscPosAccountPaymentReceiptData` (`:413`, `:436`) | `const currencySymbol = getCurrencySymbol(payload.currency_code);` | `currency_code: payload.currency_code,`<br>`locale: i18next.language,`<br>`ht_totals: null,` |
| `buildEscPosAccountChargeReceiptData` (`:481`, `:518`) | `const currencySymbol = getCurrencySymbol(input.currencyCode);` | `currency_code: input.currencyCode,`<br>`locale: i18next.language,`<br>`ht_totals: null,` |
| `buildEscPosRefundReceiptData` (`:730`, `:759`) | `const currencySymbol = getCurrencySymbol(response.currency);` | `currency_code: response.currency,`<br>`locale: i18next.language,`<br>`ht_totals: null,` |

`buildVoucherTicketData` (`:664`, `:679`) is **unchanged** — it keeps `getCurrencySymbol`, which therefore stays in the file.

Add `computeHtTotals` above `buildEscPosReceiptData` (i.e. after the `getCurrencySymbol` helper, ~line 61):

```ts
/** One sealed VAT-ventilation row as the API returns it (types/receipt.ts:147-160). */
export interface SealedVatRow {
  tax_rate: string;
  net_amount: string;
  vat_amount: string;
  gross_amount: string;
  discount_allocated?: string | null;
}

/**
 * Restate the totals block tax-exclusively, ONCE, on the device.
 *
 * `discount_allocated` is the rate group's share of the ticket's TTC remise
 * (FiscalPayloadConstraintValidator.php:1595-1639 asserts
 * `Σ discount_allocated == transaction_discount_amount`), so the HT share is
 * `allocated / (1 + rate/100)`. The quotient is rounded ONCE per rate at
 * currency scale (rule 19: intermediates at scale+4, round at the boundary),
 * and the per-rate HT base is then derived as `net + discountHt` — which makes
 * `Σ base − Σ discountHt == Σ net` exact regardless of rounding.
 *
 * Returns null when the receipt was sealed before D-1 (any row without a
 * share) or carries no ventilation at all; buildReceiptDoc then keeps the TTC
 * block and marks `doc.fallback = 'subtotal-mode'`.
 */
export function computeHtTotals(
  vatRows: readonly SealedVatRow[],
  decimals: number,
): ReceiptHtTotals | null {
  if (vatRows.length === 0) {
    return null;
  }

  const intermediate = decimals + 4;
  let subtotalHt = bcformat('0', decimals);
  let discountHt = bcformat('0', decimals);

  for (const row of vatRows) {
    const allocated = row.discount_allocated;
    if (allocated === null || allocated === undefined) {
      return null;
    }
    const divisor = bcadd('1', bcdiv(row.tax_rate, '100', intermediate), intermediate);
    const rowDiscountHt = bcdiv(allocated, divisor, decimals);
    const rowBaseHt = bcadd(row.net_amount, rowDiscountHt, decimals);
    discountHt = bcadd(discountHt, rowDiscountHt, decimals);
    subtotalHt = bcadd(subtotalHt, rowBaseHt, decimals);
  }

  return { subtotal_ht: subtotalHt, discount_ht: discountHt };
}
```

Extend the imports at `apps/pos/src/lib/buildReceiptData.ts:11`:

```ts
import { bcadd, bcsub, bccomp, bcdiv, bcformat } from '@/lib/decimal';
```

and at `:2-8` add `ReceiptHtTotals` to the type import from `@/lib/printing`.

`buildEscPosFromOfflineReceipt` builds its VAT block from the sealed offline breakdown; pass that same array to `computeHtTotals`. If the offline `CheckoutResult` exposes the rows under a different property name than `vatBreakdown`, use the property the builder already reads at `apps/pos/src/lib/buildReceiptData.ts:300-330` — do not invent a new one, and do not derive rows from the cart (D-1 forbids it, `:300-305`).

- [ ] **Step 6: Extend `buildReceiptLabels`**

In `apps/pos/src/lib/buildReceiptData.ts:557-606`, add four entries inside the returned object, after `tel: t('tel'),`:

```ts
    qr_scan_label: t('qrScanLabel'),
    subtotal_ht: t('subtotalHt'),
    discount_ht: t('discountHt'),
    total_ttc: t('totalTtc'),
```

Add the keys to **`apps/pos/src/locales/fr/pos.json`** inside `receiptLabel` (after `"tel"`):

```json
      "qrScanLabel": "Scanner pour retour / échange",
      "subtotalHt": "Sous-total HT :",
      "discountHt": "Remise HT :",
      "totalTtc": "TOTAL TTC :",
```

and to **`apps/pos/src/locales/en/pos.json`** inside `receiptLabel`:

```json
      "qrScanLabel": "Scan for return / exchange",
      "subtotalHt": "Subtotal excl. tax:",
      "discountHt": "Discount excl. tax:",
      "totalTtc": "TOTAL incl. tax:",
```

`apps/pos/src/locales/` has **only `en` and `fr`** (no `ar` directory), so there is no Arabic POS file to edit. The Arabic strings for the *web* surface land in PR 2.

- [ ] **Step 7: Update the Z-report call site and the existing POS test**

`apps/pos/src/components/Header.tsx:647` — replace `currencySymbol: '',` with:

```tsx
      currencyCode: '',
      locale: i18next.language,
```

and add `import i18next from 'i18next';` to the import block at the top of `Header.tsx` if it is not already imported.

`apps/pos/src/lib/__tests__/printing.test.ts:12,38,53` — replace each `currencySymbol: '€',` with:

```ts
      currencyCode: 'EUR',
      locale: 'fr',
```

- [ ] **Step 8: Write the fixture**

`packages/shared/src/receipt/fixtures/sampleReceipt.ts` (new). Figures are internally exact: gross TTC 11.000, remise 1.000 allocated 0.818 / 0.182, post-remise gross 8.182 / 1.818, nets 7.647 / 1.528, VAT 0.535 / 0.290, `Σ net + Σ vat = 10.000 = TOTAL`, and `subtotal` is the D-1 TTC-before-remise figure (`buildReceiptData.ts:217-235`).

```ts
import type { ReceiptData } from '../receiptData';

/**
 * The Lane E reference ticket (Phase-1 report §4): a Tunisian café whose
 * `tax_id` and `vat_number` are the SAME value — the fixture that makes the
 * duplicate matricule (DEV-QA-085) observable — with accented product names
 * (DEV-QA-087), a remise, one cash payment and a signed QR token.
 *
 * Consumed by the shared Vitest snapshots, by the cross-language contract
 * JSON, by the Rust golden-bytes regression and by the web receipt preview.
 * Changing a figure here changes four committed artefacts: re-run
 * `pnpm --filter @autoerp/shared test -u` and the Task 10 cargo gate.
 */
export const sampleReceipt: ReceiptData = {
  company: {
    name: 'Café Nour',
    address_line1: '12 rue de la Kasbah',
    address_line2: null,
    city: 'Tunis',
    postal_code: '1000',
    country: 'TN',
    tax_id: '1234567/A/M/000',
    phone: '+216 71 000 000',
    vat_number: '1234567/A/M/000',
    legal_identifier_lines: null,
  },
  receipt_number: 'R-T1-2026-00000123',
  date_time: '17/09/2026 14:32:05',
  terminal_name: 'Caisse 1',
  operator_name: 'Amine',
  lines: [
    {
      name: 'Café crème',
      quantity: '2',
      unit_price: '4.500',
      line_total: '9.000',
      modifiers: null,
      discount: null,
    },
    {
      name: 'Garçon',
      quantity: '1',
      unit_price: '2.000',
      line_total: '2.000',
      modifiers: null,
      discount: null,
    },
  ],
  subtotal: '11.000',
  discount_amount: '1.000',
  tax_amount: '0.825',
  total: '10.000',
  currency_code: 'TND',
  locale: 'fr',
  vat_breakdown: [
    { rate: '7', taxable: '7.647', tax: '0.535' },
    { rate: '19', taxable: '1.528', tax: '0.290' },
  ],
  payments: [{ method: 'Espèces', amount: '10.000' }],
  change_due: '0.000',
  tolerance_writeoff: null,
  has_tolerance: false,
  cash_rounding_adjustment: null,
  has_cash_rounding: false,
  ht_totals: { subtotal_ht: '10.092', discount_ht: '0.917' },
  fiscal_hash: 'a3f1c9e27b48d05613fe72a9c4d80b1e5f6a7382c9d0e1f2a3b4c5d6e7f80912',
  fiscal_signature: 'MEUCIQDxLaneE',
  customer_name: null,
  notes: null,
  labels: {
    receipt: 'Reçu :',
    date: 'Date :',
    terminal: 'Terminal :',
    operator: 'Opérateur :',
    customer: 'Client :',
    item: 'Article',
    qty: 'Qté',
    amount: 'Montant',
    subtotal: 'Sous-total :',
    discount: 'Remise :',
    tax: 'TVA :',
    total: 'TOTAL :',
    payments: 'Paiements :',
    change_due: 'Monnaie Rendue :',
    rounding: 'Arrondi',
    tolerance: 'Écart accepté',
    vat_rate: 'TVA %',
    taxable: 'Base HT',
    tax_col: 'TVA',
    thank_you: 'Merci pour votre achat !',
    tax_id: 'MF :',
    vat_number: 'N° TVA :',
    tel: 'Tél :',
    qr_scan_label: 'Scanner pour retour / échange',
    subtotal_ht: 'Sous-total HT :',
    discount_ht: 'Remise HT :',
    total_ttc: 'TOTAL TTC :',
  },
  show_vat_breakdown: true,
  show_fiscal_info: true,
  show_payment_details: true,
  show_customer: true,
  qr_token: 'v1:kid1:0199a0f3-5b1c-7c42-9f0e-2d7a1b3c4d5e:9f3a7c2e',
  receipt_kind: 'sale',
};
```

- [ ] **Step 9: Run every affected suite**

Run: `pnpm --filter @autoerp/shared test` → PASS (18 tests).
Run: `pnpm --filter @autoerp/pos test` → PASS (whole POS suite; the three fiscal parity suites named in Global Constraints must be green and their snapshots unmodified — confirm with `git status apps/pos/src/lib/fiscal`).
Run: `pnpm --filter @autoerp/pos typecheck` → PASS.
Run: `pnpm --filter @autoerp/web typecheck` → PASS (it typechecks the shared source it aliases).

- [ ] **Step 10: Commit**

```bash
git add packages/shared/src/receipt apps/pos/src/lib/printing.ts apps/pos/src/lib/buildReceiptData.ts \
        apps/pos/src/components/Header.tsx apps/pos/src/lib/__tests__ apps/pos/src/locales
git commit -m "refactor(pos-receipt): move ReceiptData into @autoerp/shared, add currency_code/locale/ht_totals + fixture"
```

---

### Task 4: `buildReceiptDoc` — company header, receipt meta, banners, tax-id dedup

Ports `format_receipt_with_settings` lines `:302-317` (setup), `:318-374` (company header), `:375-434` (receipt meta + customer), `:436-461` (TRAINING + DUPLICATA), `:463-505` (refund banner + original-ticket QR) and `:506` (`separator('=')`).

Fixes **DEV-QA-085**: `receipt_template.rs:347-353` prints `MF : <tax_id>` and `:356-364` prints `N° TVA : <vat_number>` as two unconditional independent lines, while `buildReceiptData.ts:195,197` fills both from the same establishment record — in Tunisia the matricule fiscal *is* the VAT identifier (`apps/api/database/seeders/CountriesSeeder.php:30`). The dedup is **display-only** and lives here; `apps/pos/src/lib/fiscal/sellerIdentity.ts` is not touched.

**Files:**
- Create: `packages/shared/src/receipt/buildReceiptDoc.ts`
- Modify: `packages/shared/src/receipt/index.ts`
- Test: `packages/shared/src/receipt/__tests__/buildReceiptDoc.header.test.ts`

**Interfaces:**
- Consumes: `ReceiptData`, `ReceiptDisplaySettings`, `ReceiptPrintContext`, `ReceiptDoc`, `ReceiptSegment`, `ReceiptLabels` (Tasks 1, 3); `formatReceiptMoney` (Task 2); `sampleReceipt` (Task 3).
- Produces:
  - `buildReceiptDoc(data: ReceiptData, display: ReceiptDisplaySettings, print: ReceiptPrintContext): ReceiptDoc`
  - `normalizeTaxIdentifier(value: string): string`
  - module-private `BuildContext`, `makeContext`, `nonEmptyTrimmed`, `appendHeader`, `appendMeta`, `appendBanners` — Tasks 5 and 6 append `appendBody` and `appendFooter` to the same file and call them from `buildReceiptDoc`.

- [ ] **Step 1: Write the failing test**

`packages/shared/src/receipt/__tests__/buildReceiptDoc.header.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { buildReceiptDoc, normalizeTaxIdentifier } from '../buildReceiptDoc';
import { sampleReceipt } from '../fixtures/sampleReceipt';
import type { ReceiptDisplaySettings, ReceiptPrintContext, ReceiptSegment } from '../index';

const display: ReceiptDisplaySettings = {
  logo: false,
  subtotalMode: 'TTC',
  showVatBreakdown: true,
  showFiscalInfo: true,
  showPaymentDetails: true,
  showCustomer: true,
};

const print: ReceiptPrintContext = { columns: 42, cutMode: 'partial', footerText: '' };

function texts(segments: readonly ReceiptSegment[]): string[] {
  return segments.flatMap((segment) => (segment.kind === 'text' ? [segment.text] : []));
}

describe('buildReceiptDoc — company header', () => {
  it('prints the company name centred, double size and bold', () => {
    const [first] = buildReceiptDoc(sampleReceipt, display, print).segments;
    expect(first).toEqual({
      kind: 'text',
      text: 'Café Nour',
      align: 'center',
      bold: true,
      size: 'double',
    });
  });

  it('joins postal code and city, skipping empty parts (receipt_template.rs:333-340)', () => {
    const doc = buildReceiptDoc(
      { ...sampleReceipt, company: { ...sampleReceipt.company, postal_code: '  ' } },
      display,
      print,
    );
    expect(texts(doc.segments)).toContain('Tunis');
    expect(texts(doc.segments)).not.toContain(' Tunis');
  });

  it('DEV-QA-085: prints the matricule ONCE when vat_number equals tax_id', () => {
    const lines = texts(buildReceiptDoc(sampleReceipt, display, print).segments);
    expect(lines).toContain('MF : 1234567/A/M/000');
    expect(lines.filter((line) => line.includes('1234567/A/M/000'))).toHaveLength(1);
    expect(lines.some((line) => line.startsWith('N° TVA :'))).toBe(false);
  });

  it('DEV-QA-085: still prints both when the establishment VAT number really differs', () => {
    const doc = buildReceiptDoc(
      { ...sampleReceipt, company: { ...sampleReceipt.company, vat_number: 'FR40303265045' } },
      display,
      print,
    );
    const lines = texts(doc.segments);
    expect(lines).toContain('MF : 1234567/A/M/000');
    expect(lines).toContain('N° TVA : FR40303265045');
  });

  it('DEV-QA-085: the dedup ignores case and surrounding or inner whitespace', () => {
    const doc = buildReceiptDoc(
      { ...sampleReceipt, company: { ...sampleReceipt.company, vat_number: ' 1234567 / a / m / 000 ' } },
      display,
      print,
    );
    expect(texts(doc.segments).some((line) => line.startsWith('N° TVA :'))).toBe(false);
  });

  it('skips blank optional legal fields and trims present ones (ports receipt_header_omits_blank_optional_legal_fields / _trims_present_)', () => {
    const doc = buildReceiptDoc(
      {
        ...sampleReceipt,
        company: {
          ...sampleReceipt.company,
          phone: '   ',
          address_line2: '  ',
          vat_number: '  FR40303265045  ',
          legal_identifier_lines: ['  SIRET: 55210055400014  ', '   '],
        },
      },
      display,
      print,
    );
    const lines = texts(doc.segments);
    expect(lines.some((line) => line.startsWith('Tél :'))).toBe(false);
    expect(lines).toContain('N° TVA : FR40303265045');
    expect(lines).toContain('SIRET: 55210055400014');
    expect(lines.filter((line) => line.trim() === '')).toHaveLength(0);
  });

  it('emits the logo placeholder only when the logo is switched on', () => {
    expect(buildReceiptDoc(sampleReceipt, display, print).segments[0]?.kind).toBe('text');
    expect(
      buildReceiptDoc(sampleReceipt, { ...display, logo: true }, print).segments[0],
    ).toEqual({ kind: 'logo' });
  });
});

describe('buildReceiptDoc — receipt meta and banners', () => {
  it('prints the four meta rows as two-column segments in the Rust order', () => {
    const pairs = buildReceiptDoc(sampleReceipt, display, print).segments.flatMap((segment) =>
      segment.kind === 'two-column' ? [[segment.left, segment.right]] : [],
    );
    expect(pairs.slice(0, 4)).toEqual([
      ['Reçu :', 'R-T1-2026-00000123'],
      ['Date :', '17/09/2026 14:32:05'],
      ['Terminal :', 'Caisse 1'],
      ['Opérateur :', 'Amine'],
    ]);
  });

  it('hides the customer block when showCustomer is off', () => {
    const doc = buildReceiptDoc(
      { ...sampleReceipt, customer_name: 'Leïla' },
      { ...display, showCustomer: false },
      print,
    );
    expect(JSON.stringify(doc.segments)).not.toContain('Leïla');
  });

  it('prints DUPLICATA centred and double-size on a reprint', () => {
    const doc = buildReceiptDoc({ ...sampleReceipt, is_reprint: true }, display, print);
    expect(doc.segments).toContainEqual({
      kind: 'text',
      text: 'DUPLICATA',
      align: 'center',
      bold: true,
      size: 'double',
    });
  });

  it('prints the refund banner, the original ticket reference and its labelled QR', () => {
    const doc = buildReceiptDoc(
      {
        ...sampleReceipt,
        receipt_kind: 'refund',
        original_receipt_number: 'R-T1-2026-00000100',
        original_receipt_qr_token: 'v1:kid1:1111:aaaa',
        labels: { ...sampleReceipt.labels, refund_header: 'REMBOURSEMENT', original_ticket: 'Ticket original :', original_qr_label: 'Scanner le ticket original :' },
      },
      display,
      print,
    );
    expect(texts(doc.segments)).toContain('REMBOURSEMENT');
    expect(doc.segments).toContainEqual({ kind: 'two-column', left: 'Ticket original :', right: 'R-T1-2026-00000100' });
    expect(doc.segments).toContainEqual({
      kind: 'qr',
      payload: 'v1:kid1:1111:aaaa',
      moduleSize: 4,
      label: 'Scanner le ticket original :',
    });
  });

  it('falls back to the English Rust defaults when a label is missing (receipt_template.rs:186-192)', () => {
    const doc = buildReceiptDoc({ ...sampleReceipt, labels: undefined }, display, print);
    const pairs = doc.segments.flatMap((segment) =>
      segment.kind === 'two-column' ? [segment.left] : [],
    );
    expect(pairs.slice(0, 4)).toEqual(['Receipt:', 'Date:', 'Terminal:', 'Operator:']);
    expect(texts(doc.segments)).toContain('Tax ID: 1234567/A/M/000');
  });
});

describe('normalizeTaxIdentifier', () => {
  it('trims, upper-cases and removes every inner space', () => {
    expect(normalizeTaxIdentifier(' 1234567 / a / m / 000 ')).toBe('1234567/A/M/000');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `pnpm --filter @autoerp/shared test`
Expected: FAIL — `Cannot find module '../buildReceiptDoc'`.

- [ ] **Step 3: Write the builder skeleton and the header/meta/banner ports**

`packages/shared/src/receipt/buildReceiptDoc.ts` (new):

```ts
import { formatReceiptMoney } from './money';
import type { ReceiptData, ReceiptLabels } from './receiptData';
import type {
  ReceiptDisplaySettings,
  ReceiptDoc,
  ReceiptPrintContext,
  ReceiptSegment,
} from './types';

/**
 * THE printed-ticket layout (spec 2026-09-17, Lane E).
 *
 * A branch-for-branch port of `format_receipt_with_settings`
 * (apps/pos/src-tauri/src/printing/receipt_template.rs:302-868), which this PR
 * deletes. Rust becomes a pure encoder; the web preview renders the same
 * document at the same column count, so the preview is faithful BY
 * CONSTRUCTION rather than by imitation.
 *
 * Pure, synchronous, dependency-free and NEVER throws (spec §3): an unknown
 * subtotal mode degrades to TTC, a missing label falls back to the Rust
 * English default, an empty company field is skipped.
 *
 * It performs NO arithmetic on money: every figure it prints arrives already
 * at currency scale from apps/pos/src/lib/buildReceiptData.ts (rule 19).
 */

interface BuildContext {
  data: ReceiptData;
  display: ReceiptDisplaySettings;
  print: ReceiptPrintContext;
  /** Localized label with the Rust English default (receipt_template.rs:186-192). */
  label: (pick: (labels: ReceiptLabels) => string | undefined, fallback: string) => string;
  /** Currency cell, formatted once, here (DEV-QA-088). */
  money: (amount: string) => string;
}

/** Port of `non_empty_trimmed` (receipt_template.rs:870-877). */
function nonEmptyTrimmed(value: string | null | undefined): string | null {
  if (value === null || value === undefined) {
    return null;
  }
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * Comparison form of a fiscal identifier: trimmed, upper-cased and stripped of
 * every whitespace character, so `" 1234567 / a / m / 000 "` and
 * `"1234567/A/M/000"` are recognised as the same matricule (DEV-QA-085).
 *
 * Deliberately stronger than the spec's "trim, uppercase": an operator-typed
 * space must not resurrect the duplicate line. Display-only — the value that
 * PRINTS is always the original string.
 */
export function normalizeTaxIdentifier(value: string): string {
  return value.trim().toUpperCase().replace(/\s+/gu, '');
}

function makeContext(
  data: ReceiptData,
  display: ReceiptDisplaySettings,
  print: ReceiptPrintContext,
): BuildContext {
  return {
    data,
    display,
    print,
    label: (pick, fallback) => {
      const labels = data.labels;
      if (labels === undefined) {
        return fallback;
      }
      const value = pick(labels);
      return value === undefined ? fallback : value;
    },
    money: (amount) => formatReceiptMoney(amount, data.currency_code, data.locale),
  };
}

/** Company header — port of receipt_template.rs:318-374. */
function appendHeader(segments: ReceiptSegment[], ctx: BuildContext): void {
  const { company } = ctx.data;

  // Logo placeholder. No raster command exists in escpos.rs, so the ENCODER
  // emits nothing for this segment and only the preview and the PDF show it
  // (owner ruling 5; thermal raster is ticket E-1).
  if (ctx.display.logo) {
    segments.push({ kind: 'logo' });
  }

  segments.push({
    kind: 'text',
    text: company.name,
    align: 'center',
    bold: true,
    size: 'double',
  });
  segments.push({ kind: 'text', text: company.address_line1, align: 'center' });

  const addressLine2 = nonEmptyTrimmed(company.address_line2);
  if (addressLine2 !== null) {
    segments.push({ kind: 'text', text: addressLine2, align: 'center' });
  }

  const postalCity = [company.postal_code.trim(), company.city.trim()]
    .filter((part) => part !== '')
    .join(' ');
  if (postalCity !== '') {
    segments.push({ kind: 'text', text: postalCity, align: 'center' });
  }

  const phone = nonEmptyTrimmed(company.phone);
  if (phone !== null) {
    const label = ctx.label((labels) => labels.tel, 'Tel:');
    segments.push({ kind: 'text', text: `${label} ${phone}`, align: 'center' });
  }

  const taxId = nonEmptyTrimmed(company.tax_id);
  if (taxId !== null) {
    const label = ctx.label((labels) => labels.tax_id, 'Tax ID:');
    segments.push({ kind: 'text', text: `${label} ${taxId}`, align: 'center' });
  }

  // DEV-QA-085. receipt_template.rs:356-364 printed this line unconditionally
  // beside the tax id, and buildReceiptData.ts:195,197 fills both from the SAME
  // establishment record — so in Tunisia the matricule printed twice. Display
  // only: resolveSellerIdentity and the signed seller block are untouched.
  const vatNumber = nonEmptyTrimmed(company.vat_number);
  if (
    vatNumber !== null &&
    (taxId === null || normalizeTaxIdentifier(vatNumber) !== normalizeTaxIdentifier(taxId))
  ) {
    const label = ctx.label((labels) => labels.vat_number, 'VAT No:');
    segments.push({ kind: 'text', text: `${label} ${vatNumber}`, align: 'center' });
  }

  for (const line of company.legal_identifier_lines ?? []) {
    const value = nonEmptyTrimmed(line);
    if (value !== null) {
      segments.push({ kind: 'text', text: value, align: 'center' });
    }
  }

  segments.push({ kind: 'separator', char: '-' });
}

/** Receipt meta + customer block — port of receipt_template.rs:375-434. */
function appendMeta(segments: ReceiptSegment[], ctx: BuildContext): void {
  const { data } = ctx;

  segments.push({
    kind: 'two-column',
    left: ctx.label((labels) => labels.receipt, 'Receipt:'),
    right: data.receipt_number,
  });
  segments.push({
    kind: 'two-column',
    left: ctx.label((labels) => labels.date, 'Date:'),
    right: data.date_time,
  });
  segments.push({
    kind: 'two-column',
    left: ctx.label((labels) => labels.terminal, 'Terminal:'),
    right: data.terminal_name,
  });
  segments.push({
    kind: 'two-column',
    left: ctx.label((labels) => labels.operator, 'Operator:'),
    right: data.operator_name,
  });

  const businessDate = nonEmptyTrimmed(data.business_date);
  if (businessDate !== null) {
    const label = ctx.label((labels) => labels.business_date, 'Business date:');
    segments.push({ kind: 'text', text: `${label} ${businessDate}` });
  }
  const terminalId = nonEmptyTrimmed(data.terminal_id);
  if (terminalId !== null) {
    const label = ctx.label((labels) => labels.terminal_id, 'Terminal ID:');
    segments.push({ kind: 'text', text: `${label} ${terminalId}` });
  }
  const shiftId = nonEmptyTrimmed(data.shift_id);
  if (shiftId !== null) {
    const label = ctx.label((labels) => labels.shift_id, 'Shift ID:');
    segments.push({ kind: 'text', text: `${label} ${shiftId}` });
  }

  if (ctx.display.showCustomer) {
    if (data.customer_name !== null) {
      segments.push({
        kind: 'two-column',
        left: ctx.label((labels) => labels.customer, 'Customer:'),
        right: data.customer_name,
      });
    }
    const accountId = nonEmptyTrimmed(data.customer_account_id);
    if (accountId !== null) {
      const label = ctx.label((labels) => labels.customer_account, 'Account:');
      segments.push({ kind: 'text', text: `${label} ${accountId}` });
    }
    const customerPhone = nonEmptyTrimmed(data.customer_phone);
    if (customerPhone !== null) {
      const label = ctx.label((labels) => labels.customer_phone, 'Customer phone:');
      segments.push({ kind: 'text', text: `${label} ${customerPhone}` });
    }
  }
}

/** TRAINING / DUPLICATA / REFUND banners — port of receipt_template.rs:436-506. */
function appendBanners(segments: ReceiptSegment[], ctx: BuildContext): void {
  const { data } = ctx;

  if (data.training_flag === true) {
    segments.push({
      kind: 'text',
      text: ctx.label((labels) => labels.training, 'TRAINING'),
      align: 'center',
      bold: true,
      size: 'double',
    });
  }

  if (data.is_reprint === true) {
    segments.push({ kind: 'text', text: 'DUPLICATA', align: 'center', bold: true, size: 'double' });
  }

  if (isRefund(data)) {
    segments.push({
      kind: 'text',
      text: ctx.label((labels) => labels.refund_header, 'REFUND'),
      align: 'center',
      bold: true,
      size: 'double',
    });

    if (data.original_receipt_number !== null && data.original_receipt_number !== undefined) {
      segments.push({
        kind: 'two-column',
        left: ctx.label((labels) => labels.original_ticket, 'Original ticket:'),
        right: data.original_receipt_number,
      });
    }

    const originalToken = nonEmptyTrimmed(data.original_receipt_qr_token);
    if (originalToken !== null) {
      segments.push({ kind: 'blank' });
      segments.push({
        kind: 'qr',
        payload: originalToken,
        moduleSize: 4,
        label: ctx.label((labels) => labels.original_qr_label, 'Scan original ticket:'),
      });
      segments.push({ kind: 'blank' });
    }
  }

  segments.push({ kind: 'separator', char: '=' });
}

/** Port of the case-insensitive `receipt_kind` test at receipt_template.rs:465-469. */
function isRefund(data: ReceiptData): boolean {
  return (data.receipt_kind ?? '').toLowerCase() === 'refund';
}

/** Port of the case-insensitive `receipt_kind` test at receipt_template.rs:470-474. */
function isAccountPayment(data: ReceiptData): boolean {
  return (data.receipt_kind ?? '').toLowerCase() === 'account_payment';
}

export function buildReceiptDoc(
  data: ReceiptData,
  display: ReceiptDisplaySettings,
  print: ReceiptPrintContext,
): ReceiptDoc {
  const ctx = makeContext(data, display, print);
  const segments: ReceiptSegment[] = [];

  appendHeader(segments, ctx);
  appendMeta(segments, ctx);
  appendBanners(segments, ctx);

  return { version: 1, columns: print.columns, segments };
}
```

`isAccountPayment` is used by Task 5; declaring it here keeps the `receipt_kind` branch pair (`receipt_template.rs:465-474`) in one place. If the linter flags it as unused before Task 5 lands, complete Tasks 4 and 5 in the same sitting rather than suppressing the rule.

Add to `packages/shared/src/receipt/index.ts`:

```ts
export * from './buildReceiptDoc';
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `pnpm --filter @autoerp/shared test`
Expected: PASS — 13 new tests.

- [ ] **Step 5: Commit**

```bash
git add packages/shared/src/receipt
git commit -m "fix(pos-receipt DEV-QA-085): buildReceiptDoc header/meta/banners with display-only tax-id dedup"
```

---

### Task 5: `buildReceiptDoc` — account-payment branch, line items, totals, HT/TTC modes, payments

Ports `format_receipt_with_settings` lines `:507-548` (account-payment layout), `:549-588` (line items + modifiers + line discounts), `:590-700` (totals, VAT ventilation, cash rounding, TOTAL) and `:701-728` (payments, change due, tolerance).

Two behaviour changes, both deliberate:
- **DEV-QA-088**: every monetary cell is produced by `ctx.money(...)`, so `TND11.000` becomes `11.000 TND`.
- **New (owner ruling 7)**: `display.subtotalMode === 'HT'` restates the subtotal block tax-exclusively. The `> 20 chars` branch at `:566-573` is **dropped** — both of its arms were byte-identical, so removing it changes nothing.

**Files:**
- Modify: `packages/shared/src/receipt/buildReceiptDoc.ts` (add `appendBody` and its call inside `buildReceiptDoc`)
- Test: `packages/shared/src/receipt/__tests__/buildReceiptDoc.totals.test.ts`

**Interfaces:**
- Consumes: everything Task 4 produced.
- Produces: module-private `appendBody(segments: ReceiptSegment[], ctx: BuildContext): 'subtotal-mode' | undefined`. `buildReceiptDoc` now returns `fallback` when it is defined.

- [ ] **Step 1: Write the failing test**

`packages/shared/src/receipt/__tests__/buildReceiptDoc.totals.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { buildReceiptDoc } from '../buildReceiptDoc';
import { sampleReceipt } from '../fixtures/sampleReceipt';
import type { ReceiptDisplaySettings, ReceiptPrintContext, ReceiptSegment } from '../index';

const ttc: ReceiptDisplaySettings = {
  logo: false,
  subtotalMode: 'TTC',
  showVatBreakdown: true,
  showFiscalInfo: true,
  showPaymentDetails: true,
  showCustomer: true,
};
const ht: ReceiptDisplaySettings = { ...ttc, subtotalMode: 'HT' };
const print: ReceiptPrintContext = { columns: 42, cutMode: 'partial', footerText: '' };

function pairs(segments: readonly ReceiptSegment[]): Array<[string, string]> {
  return segments.flatMap((segment) =>
    segment.kind === 'two-column' ? ([[segment.left, segment.right]] as Array<[string, string]>) : [],
  );
}
function triples(segments: readonly ReceiptSegment[]): Array<[string, string, string]> {
  return segments.flatMap((segment) =>
    segment.kind === 'three-column'
      ? ([[segment.left, segment.middle, segment.right]] as Array<[string, string, string]>)
      : [],
  );
}

describe('buildReceiptDoc — line items', () => {
  it('prints the name on its own row then quantity x unit price against the line total', () => {
    const rows = pairs(buildReceiptDoc(sampleReceipt, ttc, print).segments);
    expect(rows).toContainEqual(['  2 x 4.500', '9.000 TND']);
    expect(rows).toContainEqual(['  1 x 2.000', '2.000 TND']);
  });

  it('DEV-QA-088: every money cell is amount-then-code with a separating space', () => {
    const json = JSON.stringify(buildReceiptDoc(sampleReceipt, ttc, print).segments);
    expect(json).not.toContain('TND9.000');
    expect(json).not.toContain('TND10.000');
    expect(json).toContain('10.000 TND');
  });

  it('prints a modifier price with a plus sign and hides a zero-priced modifier', () => {
    const rows = pairs(
      buildReceiptDoc(
        {
          ...sampleReceipt,
          lines: [
            {
              ...sampleReceipt.lines[0]!,
              modifiers: [
                { name: 'Sirop', price: '0.500' },
                { name: 'Sans sucre', price: '0.00' },
              ],
            },
          ],
        },
        ttc,
        print,
      ).segments,
    );
    expect(rows).toContainEqual(['  + Sirop', '+0.500 TND']);
    expect(rows).toContainEqual(['  + Sans sucre', '']);
  });

  it('prints a line discount as a negative money cell', () => {
    const rows = pairs(
      buildReceiptDoc(
        { ...sampleReceipt, lines: [{ ...sampleReceipt.lines[0]!, discount: '0.500' }] },
        ttc,
        print,
      ).segments,
    );
    expect(rows).toContainEqual(['  Remise :', '-0.500 TND']);
  });
});

describe('buildReceiptDoc — TTC totals (today\'s post-D-1 layout)', () => {
  it('prints Sous-total (TTC before remise), Remise, the ventilation and TOTAL in that order', () => {
    const doc = buildReceiptDoc(sampleReceipt, ttc, print);
    const rows = pairs(doc.segments);
    expect(rows).toContainEqual(['Sous-total :', '11.000 TND']);
    expect(rows).toContainEqual(['Remise :', '-1.000 TND']);
    expect(rows).toContainEqual(['TOTAL :', '10.000 TND']);
    expect(triples(doc.segments)).toContainEqual(['TVA %', 'Base HT', 'TVA']);
    expect(triples(doc.segments)).toContainEqual(['7%', '7.647 TND', '0.535 TND']);
    expect(triples(doc.segments)).toContainEqual(['19%', '1.528 TND', '0.290 TND']);
    expect(doc.fallback).toBeUndefined();
  });

  it('ports a_discounted_sale_prints_the_ventilation_above_the_total_and_no_aggregate_tax_line', () => {
    const doc = buildReceiptDoc(sampleReceipt, ttc, print);
    expect(pairs(doc.segments).some(([left]) => left === 'TVA :')).toBe(false);
    const segments = doc.segments;
    const ventilation = segments.findIndex((s) => s.kind === 'three-column' && s.left === '7%');
    const total = segments.findIndex((s) => s.kind === 'two-column' && s.left === 'TOTAL :');
    expect(ventilation).toBeGreaterThan(-1);
    expect(ventilation).toBeLessThan(total);
  });

  it('ports hiding_the_vat_block_restores_the_aggregate_tax_line', () => {
    const doc = buildReceiptDoc(sampleReceipt, { ...ttc, showVatBreakdown: false }, print);
    expect(pairs(doc.segments)).toContainEqual(['TVA :', '0.825 TND']);
    expect(triples(doc.segments).some(([left]) => left === '7%')).toBe(false);
  });

  it('omits the Remise row when the ticket carries no discount', () => {
    const doc = buildReceiptDoc({ ...sampleReceipt, discount_amount: '0.00' }, ttc, print);
    expect(pairs(doc.segments).some(([left]) => left === 'Remise :')).toBe(false);
  });

  it('prints TOTAL bold and double-height', () => {
    const total = buildReceiptDoc(sampleReceipt, ttc, print).segments.find(
      (segment) => segment.kind === 'two-column' && segment.left === 'TOTAL :',
    );
    expect(total).toEqual({
      kind: 'two-column',
      left: 'TOTAL :',
      right: '10.000 TND',
      bold: true,
      size: 'double-height',
    });
  });
});

describe('buildReceiptDoc — cash rounding and tolerance (ported Rust assertions)', () => {
  const rounded = {
    ...sampleReceipt,
    cash_rounding_adjustment: '-0.005',
    has_cash_rounding: true,
    labels: { ...sampleReceipt.labels, rounding: 'Arrondi', tolerance: 'Écart accepté' },
  };

  it('ports the_rounding_line_prints_between_tax_and_total_not_below_the_payments', () => {
    const segments = buildReceiptDoc(rounded, ttc, print).segments;
    const rounding = segments.findIndex((s) => s.kind === 'two-column' && s.left === 'Arrondi');
    const total = segments.findIndex((s) => s.kind === 'two-column' && s.left === 'TOTAL :');
    const payments = segments.findIndex((s) => s.kind === 'text' && s.text === 'Paiements :');
    expect(rounding).toBeLessThan(total);
    expect(total).toBeLessThan(payments);
  });

  it('ports a_positive_adjustment_prints_without_a_forced_minus', () => {
    const doc = buildReceiptDoc({ ...rounded, cash_rounding_adjustment: '0.005' }, ttc, print);
    expect(pairs(doc.segments)).toContainEqual(['Arrondi', '0.005 TND']);
  });

  it('ports an_unrounded_sale_prints_no_rounding_line', () => {
    const doc = buildReceiptDoc(sampleReceipt, ttc, print);
    expect(pairs(doc.segments).some(([left]) => left === 'Arrondi')).toBe(false);
  });

  it('ports the_rounding_line_prints_even_when_payment_details_are_hidden', () => {
    const doc = buildReceiptDoc(rounded, { ...ttc, showPaymentDetails: false }, print);
    expect(pairs(doc.segments)).toContainEqual(['Arrondi', '-0.005 TND']);
  });

  it('ports tolerance_and_rounding_print_as_two_distinctly_labelled_lines', () => {
    const doc = buildReceiptDoc(
      { ...rounded, tolerance_writeoff: '0.010', has_tolerance: true },
      ttc,
      print,
    );
    expect(pairs(doc.segments)).toContainEqual(['Arrondi', '-0.005 TND']);
    expect(pairs(doc.segments)).toContainEqual(['Écart accepté', '-0.010 TND']);
  });

  it('prints the change-due row bold when change was given', () => {
    const doc = buildReceiptDoc({ ...sampleReceipt, change_due: '2.000' }, ttc, print);
    expect(doc.segments).toContainEqual({
      kind: 'two-column',
      left: 'Monnaie Rendue :',
      right: '2.000 TND',
      bold: true,
    });
  });
});

describe('buildReceiptDoc — HT subtotal mode (owner ruling 7)', () => {
  it('restates the subtotal block tax-exclusively and keeps TOTAL tax-inclusive', () => {
    const doc = buildReceiptDoc(sampleReceipt, ht, print);
    const rows = pairs(doc.segments);
    expect(rows).toContainEqual(['Sous-total HT :', '10.092 TND']);
    expect(rows).toContainEqual(['Remise HT :', '-0.917 TND']);
    expect(rows).toContainEqual(['TOTAL TTC :', '10.000 TND']);
    expect(rows.some(([left]) => left === 'Sous-total :')).toBe(false);
    expect(doc.fallback).toBeUndefined();
  });

  it('keeps the VAT ventilation, so 10.092 - 0.917 == 7.647 + 1.528 is checkable on the ticket', () => {
    expect(triples(buildReceiptDoc(sampleReceipt, ht, print).segments)).toContainEqual([
      '7%',
      '7.647 TND',
      '0.535 TND',
    ]);
  });

  it('falls back to TTC and marks the document when the receipt predates D-1', () => {
    const doc = buildReceiptDoc({ ...sampleReceipt, ht_totals: null }, ht, print);
    expect(doc.fallback).toBe('subtotal-mode');
    expect(pairs(doc.segments)).toContainEqual(['Sous-total :', '11.000 TND']);
    expect(pairs(doc.segments).some(([left]) => left === 'Sous-total HT :')).toBe(false);
  });

  it('never throws on an unknown mode: it degrades to TTC without a fallback marker (spec §3)', () => {
    const doc = buildReceiptDoc(
      sampleReceipt,
      { ...ttc, subtotalMode: 'XX' as unknown as typeof ttc.subtotalMode },
      print,
    );
    expect(pairs(doc.segments)).toContainEqual(['Sous-total :', '11.000 TND']);
    expect(doc.fallback).toBeUndefined();
  });
});

describe('buildReceiptDoc — account payment branch', () => {
  it('ports account_payment_receipt_uses_account_layout_without_sale_lines_or_vat', () => {
    const doc = buildReceiptDoc(
      {
        ...sampleReceipt,
        receipt_kind: 'account_payment',
        account_balance_before: '50.000',
        account_balance_after: '40.000',
        labels: {
          ...sampleReceipt.labels,
          account_payment_header: "RECU D'ENCAISSEMENT",
          balance_before: 'Solde avant :',
          balance_after: 'Solde apres :',
        },
      },
      ttc,
      print,
    );
    const rows = pairs(doc.segments);
    expect(rows).toContainEqual(['Solde avant :', '50.000 TND']);
    expect(rows).toContainEqual(['Montant', '10.000 TND']);
    expect(rows).toContainEqual(['Solde apres :', '40.000 TND']);
    expect(rows.some(([left]) => left === 'Sous-total :')).toBe(false);
    expect(triples(doc.segments).some(([left]) => left === '7%')).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `pnpm --filter @autoerp/shared vitest run src/receipt/__tests__/buildReceiptDoc.totals.test.ts`
Expected: FAIL — no line-item, totals or payment segments are produced yet.

- [ ] **Step 3: Write `appendBody`**

Append to `packages/shared/src/receipt/buildReceiptDoc.ts`, above `buildReceiptDoc`:

```ts
/** Port of the `"0.00" | "0"` zero test used throughout receipt_template.rs. */
function isPrintedZero(amount: string): boolean {
  return amount === '0.00' || amount === '0';
}

/** Account-payment layout — port of receipt_template.rs:507-548. */
function appendAccountPayment(segments: ReceiptSegment[], ctx: BuildContext): void {
  const { data } = ctx;

  segments.push({
    kind: 'text',
    text: ctx.label((labels) => labels.account_payment_header, 'ACCOUNT PAYMENT RECEIPT'),
    align: 'center',
    bold: true,
    size: 'double-height',
  });
  segments.push({ kind: 'separator', char: '-' });

  if (data.account_balance_before !== null && data.account_balance_before !== undefined) {
    segments.push({
      kind: 'two-column',
      left: ctx.label((labels) => labels.balance_before, 'Balance before:'),
      right: ctx.money(data.account_balance_before),
    });
  }
  segments.push({
    kind: 'two-column',
    left: ctx.label((labels) => labels.amount, 'Amount'),
    right: ctx.money(data.total),
  });
  if (data.account_balance_after !== null && data.account_balance_after !== undefined) {
    segments.push({
      kind: 'two-column',
      left: ctx.label((labels) => labels.balance_after, 'Balance after:'),
      right: ctx.money(data.account_balance_after),
    });
  }
  if (data.account_snapshot_stale === true) {
    segments.push({
      kind: 'text',
      text: ctx.label((labels) => labels.stale_balance, 'Balance snapshot stale'),
    });
  }

  if (ctx.display.showPaymentDetails) {
    segments.push({ kind: 'separator', char: '-' });
    segments.push({
      kind: 'text',
      text: ctx.label((labels) => labels.payments, 'Payments:'),
      bold: true,
    });
    for (const payment of data.payments) {
      segments.push({
        kind: 'two-column',
        left: `  ${payment.method}`,
        right: ctx.money(payment.amount),
      });
    }
  }
}

/** Line items, modifiers and line discounts — port of receipt_template.rs:549-588. */
function appendLineItems(segments: ReceiptSegment[], ctx: BuildContext): void {
  segments.push({
    kind: 'three-column',
    left: ctx.label((labels) => labels.item, 'Item'),
    middle: ctx.label((labels) => labels.qty, 'Qty'),
    right: ctx.label((labels) => labels.amount, 'Amount'),
    bold: true,
  });
  segments.push({ kind: 'separator', char: '-' });

  for (const line of ctx.data.lines) {
    // receipt_template.rs:566-573 branched on `name.len() > 20` — both arms
    // emitted the SAME two rows, so the branch is dropped, not ported.
    segments.push({ kind: 'text', text: line.name });
    segments.push({
      kind: 'two-column',
      left: `  ${line.quantity} x ${line.unit_price}`,
      right: ctx.money(line.line_total),
    });

    for (const modifier of line.modifiers ?? []) {
      segments.push({
        kind: 'two-column',
        left: `  + ${modifier.name}`,
        right: isPrintedZero(modifier.price) ? '' : ctx.money(`+${modifier.price}`),
      });
    }

    if (line.discount !== null) {
      segments.push({
        kind: 'two-column',
        left: `  ${ctx.label((labels) => labels.discount, 'Discount')}`,
        right: ctx.money(`-${line.discount}`),
      });
    }
  }
}

/**
 * VAT ventilation table, or the aggregate tax line when there is none.
 * Port of receipt_template.rs:613-656, including the D-1 comment's rule: the
 * aggregate `Tax:` row appears ONLY when no table carries the same information.
 */
function appendVatBlock(segments: ReceiptSegment[], ctx: BuildContext): void {
  const { data } = ctx;
  const showsTable = ctx.display.showVatBreakdown && data.vat_breakdown.length > 0;

  if (!showsTable) {
    segments.push({
      kind: 'two-column',
      left: ctx.label((labels) => labels.tax, 'Tax:'),
      right: ctx.money(data.tax_amount),
    });
    return;
  }

  segments.push({ kind: 'separator', char: '-' });
  segments.push({
    kind: 'three-column',
    left: ctx.label((labels) => labels.vat_rate, 'VAT %'),
    middle: ctx.label((labels) => labels.taxable, 'Taxable'),
    right: ctx.label((labels) => labels.tax_col, 'Tax'),
    bold: true,
  });
  for (const vat of data.vat_breakdown) {
    segments.push({
      kind: 'three-column',
      left: `${vat.rate}%`,
      middle: ctx.money(vat.taxable),
      right: ctx.money(vat.tax),
    });
  }
  segments.push({ kind: 'separator', char: '-' });
}

/**
 * Sale totals block. Port of receipt_template.rs:590-700 in TTC mode; owner
 * ruling 7's tax-exclusive restatement in HT mode.
 *
 * Returns `'subtotal-mode'` when HT was asked for and the receipt cannot state
 * it — a pre-D-1 ticket whose per-rate remise share was never sealed
 * (buildReceiptData.ts:169-171). The ticket then prints the TTC block exactly
 * as before, so an NF525 reprint stays faithful.
 */
function appendTotals(segments: ReceiptSegment[], ctx: BuildContext): 'subtotal-mode' | undefined {
  const { data } = ctx;
  const htTotals = data.ht_totals ?? null;
  const wantsHt = ctx.display.subtotalMode === 'HT';
  const fallback: 'subtotal-mode' | undefined =
    wantsHt && htTotals === null ? 'subtotal-mode' : undefined;
  const useHt = wantsHt && htTotals !== null;
  const hasDiscount = !isPrintedZero(data.discount_amount);

  if (useHt && htTotals !== null) {
    segments.push({
      kind: 'two-column',
      left: ctx.label((labels) => labels.subtotal_ht, 'Subtotal excl. tax:'),
      right: ctx.money(htTotals.subtotal_ht),
    });
    if (hasDiscount) {
      segments.push({
        kind: 'two-column',
        left: ctx.label((labels) => labels.discount_ht, 'Discount excl. tax:'),
        right: ctx.money(`-${htTotals.discount_ht}`),
      });
    }
  } else {
    segments.push({
      kind: 'two-column',
      left: ctx.label((labels) => labels.subtotal, 'Subtotal:'),
      right: ctx.money(data.subtotal),
    });
    if (hasDiscount) {
      const discountLabel = ctx.label((labels) => labels.discount, 'Discount').replace(/:+$/u, '');
      segments.push({
        kind: 'two-column',
        left: `${discountLabel}:`,
        right: ctx.money(`-${data.discount_amount}`),
      });
    }
  }

  appendVatBlock(segments, ctx);

  // Signed cash rounding — port of receipt_template.rs:658-676. Deliberately
  // between the tax block and TOTAL: it is the one line that lets the
  // customer's own arithmetic land on TOTAL, and it must survive
  // showPaymentDetails being off.
  if (data.has_cash_rounding === true) {
    const adjustment = data.cash_rounding_adjustment;
    if (adjustment !== null && adjustment !== undefined) {
      segments.push({
        kind: 'two-column',
        left: ctx.label((labels) => labels.rounding, 'Rounding'),
        right: ctx.money(adjustment),
      });
    }
  }

  segments.push({
    kind: 'two-column',
    left: useHt
      ? ctx.label((labels) => labels.total_ttc, 'TOTAL incl. tax:')
      : ctx.label((labels) => labels.total, 'TOTAL:'),
    right: ctx.money(data.total),
    bold: true,
    size: 'double-height',
  });

  return fallback;
}

/** Payments, change due and tolerance — port of receipt_template.rs:701-728. */
function appendPayments(segments: ReceiptSegment[], ctx: BuildContext): void {
  const { data } = ctx;
  if (!ctx.display.showPaymentDetails) {
    return;
  }

  segments.push({ kind: 'separator', char: '-' });
  segments.push({
    kind: 'text',
    text: ctx.label((labels) => labels.payments, 'Payments:'),
    bold: true,
  });

  for (const payment of data.payments) {
    segments.push({
      kind: 'two-column',
      left: `  ${payment.method}`,
      right: ctx.money(payment.amount),
    });
  }

  if (!isPrintedZero(data.change_due)) {
    segments.push({
      kind: 'two-column',
      left: ctx.label((labels) => labels.change_due, 'Change Due:'),
      right: ctx.money(data.change_due),
      bold: true,
    });
  }

  if (data.has_tolerance === true) {
    const tolerance = data.tolerance_writeoff;
    if (tolerance !== null && tolerance !== undefined) {
      segments.push({
        kind: 'two-column',
        left: ctx.label((labels) => labels.tolerance, 'Tolerance'),
        right: ctx.money(`-${tolerance}`),
      });
    }
  }
}

function appendBody(segments: ReceiptSegment[], ctx: BuildContext): 'subtotal-mode' | undefined {
  if (isAccountPayment(ctx.data)) {
    appendAccountPayment(segments, ctx);
    return undefined;
  }

  appendLineItems(segments, ctx);
  segments.push({ kind: 'separator', char: '=' });
  const fallback = appendTotals(segments, ctx);
  appendPayments(segments, ctx);
  return fallback;
}
```

Wire it into `buildReceiptDoc` (replace its body):

```ts
export function buildReceiptDoc(
  data: ReceiptData,
  display: ReceiptDisplaySettings,
  print: ReceiptPrintContext,
): ReceiptDoc {
  const ctx = makeContext(data, display, print);
  const segments: ReceiptSegment[] = [];

  appendHeader(segments, ctx);
  appendMeta(segments, ctx);
  appendBanners(segments, ctx);
  const fallback = appendBody(segments, ctx);

  return {
    version: 1,
    columns: print.columns,
    segments,
    ...(fallback === undefined ? {} : { fallback }),
  };
}
```

The conditional spread is required: `exactOptionalPropertyTypes` (Global Constraints) rejects `fallback: undefined`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `pnpm --filter @autoerp/shared test`
Expected: PASS — 19 new tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add packages/shared/src/receipt
git commit -m "fix(pos-receipt DEV-QA-088): buildReceiptDoc items, totals, HT/TTC modes and payments"
```

---

### Task 6: `buildReceiptDoc` — notes, fiscal footer, the ONE labelled QR, footer text, Z cash-count block, tail

Ports `format_receipt_with_settings` lines `:730-736` (notes), `:737-770` (fiscal compliance footer), `:772-790` (receipt QR token), `:792-804` (footer text), `:806-856` (Z cash-count block) and `:858-865` (feed + cut), plus the cash-count formatters `:879-913`.

Fixes **DEV-QA-086**. Today a sale ticket carries up to two unlabelled QRs and a refund up to three: the fiscal-hash QR (`:760-764`) duplicates the `Hash:` text printed two lines above (`:745-757`), and the workflow QR (`:775-788`) has no caption on sales (refunds get one at `:496-497`). Owner ruling 6: **drop the fiscal-hash QR, keep ONE labelled refund-lookup QR**. The hash and signature stay as text — no fiscal information is lost.

**Files:**
- Modify: `packages/shared/src/receipt/buildReceiptDoc.ts` (add `appendFooter` and its call)
- Test: `packages/shared/src/receipt/__tests__/buildReceiptDoc.footer.test.ts`
- Test: `packages/shared/src/receipt/__tests__/buildReceiptDoc.snapshot.test.ts`

**Interfaces:**
- Consumes: everything Tasks 4-5 produced.
- Produces: module-private `appendFooter(segments: ReceiptSegment[], ctx: BuildContext): void`, `formatCashCountHeader(ctx, columns)`, `formatCashCountRow(row, columns)`. `buildReceiptDoc` is complete after this task.

- [ ] **Step 1: Write the failing tests**

`packages/shared/src/receipt/__tests__/buildReceiptDoc.footer.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { buildReceiptDoc } from '../buildReceiptDoc';
import { sampleReceipt } from '../fixtures/sampleReceipt';
import type { ReceiptDisplaySettings, ReceiptPrintContext, ReceiptSegment } from '../index';

const display: ReceiptDisplaySettings = {
  logo: false,
  subtotalMode: 'TTC',
  showVatBreakdown: true,
  showFiscalInfo: true,
  showPaymentDetails: true,
  showCustomer: true,
};
const print: ReceiptPrintContext = { columns: 42, cutMode: 'partial', footerText: '' };

function qrs(segments: readonly ReceiptSegment[]) {
  return segments.filter((segment) => segment.kind === 'qr');
}
function texts(segments: readonly ReceiptSegment[]): string[] {
  return segments.flatMap((segment) => (segment.kind === 'text' ? [segment.text] : []));
}

describe('buildReceiptDoc — fiscal footer and QR (DEV-QA-086)', () => {
  it('prints exactly ONE QR on a sale, and it is the return/exchange token with a caption', () => {
    const doc = buildReceiptDoc(sampleReceipt, display, print);
    expect(qrs(doc.segments)).toEqual([
      {
        kind: 'qr',
        payload: 'v1:kid1:0199a0f3-5b1c-7c42-9f0e-2d7a1b3c4d5e:9f3a7c2e',
        moduleSize: 4,
        label: 'Scanner pour retour / échange',
      },
    ]);
  });

  it('never emits a QR whose payload is the fiscal hash', () => {
    const doc = buildReceiptDoc(sampleReceipt, display, print);
    expect(qrs(doc.segments).some((qr) => qr.kind === 'qr' && qr.payload === sampleReceipt.fiscal_hash)).toBe(false);
  });

  it('keeps the hash and signature as readable text (nothing fiscal is lost)', () => {
    const lines = texts(buildReceiptDoc(sampleReceipt, display, print).segments);
    expect(lines).toContain('Hash: a3f1c9e27b48d056...e7f80912');
    expect(lines).toContain('Sig: MEUCIQDxLaneE');
  });

  it('prints a short hash whole (<= 32 chars, receipt_template.rs:746-752)', () => {
    const lines = texts(
      buildReceiptDoc({ ...sampleReceipt, fiscal_hash: 'abc123' }, display, print).segments,
    );
    expect(lines).toContain('Hash: abc123');
  });

  it('hides the whole fiscal block when showFiscalInfo is off, but keeps the token QR', () => {
    const doc = buildReceiptDoc(sampleReceipt, { ...display, showFiscalInfo: false }, print);
    expect(texts(doc.segments).some((line) => line.startsWith('Hash:'))).toBe(false);
    expect(qrs(doc.segments)).toHaveLength(1);
  });

  it('emits no QR at all when the receipt has no signed token (offline ticket)', () => {
    expect(qrs(buildReceiptDoc({ ...sampleReceipt, qr_token: null }, display, print).segments)).toHaveLength(0);
  });

  it('keeps the human-readable token under the QR so a damaged code can be typed in', () => {
    expect(texts(buildReceiptDoc(sampleReceipt, display, print).segments)).toContain(
      'v1:kid1:0199a0f3-5b1c-7c42-9f0e-2d7a1b3c4d5e:9f3a7c2e',
    );
  });

  it('a refund keeps its original-ticket QR plus its own token QR: two, both labelled', () => {
    const doc = buildReceiptDoc(
      {
        ...sampleReceipt,
        receipt_kind: 'refund',
        original_receipt_qr_token: 'v1:kid1:1111:aaaa',
        labels: { ...sampleReceipt.labels, original_qr_label: 'Scanner le ticket original :' },
      },
      display,
      print,
    );
    const codes = qrs(doc.segments);
    expect(codes).toHaveLength(2);
    expect(codes.every((qr) => qr.kind === 'qr' && qr.label !== undefined && qr.label !== '')).toBe(true);
  });
});

describe('buildReceiptDoc — footer text', () => {
  it('prints the device footer text when the printer store carries one', () => {
    const doc = buildReceiptDoc(sampleReceipt, display, { ...print, footerText: 'A bientôt !' });
    expect(texts(doc.segments)).toContain('A bientôt !');
    expect(texts(doc.segments)).not.toContain('Merci pour votre achat !');
  });

  it('falls back to the thank-you label when the device footer is empty', () => {
    expect(texts(buildReceiptDoc(sampleReceipt, display, print).segments)).toContain(
      'Merci pour votre achat !',
    );
  });
});

describe('buildReceiptDoc — Z cash-count block (ports z_receipt_includes_cash_count_block_when_present)', () => {
  const zReceipt = {
    ...sampleReceipt,
    lines: [],
    cash_counts: [
      { code: 'CASH', name: 'Espèces', expected: '150.000', actual: '155.000', variance: '5.000', direction: 'over' as const },
      { code: 'CARD', name: 'Carte', expected: '90.000', actual: '90.000', variance: '0.000', direction: 'balanced' as const },
    ],
    aggregate_variance: '5.000',
    manager_name: 'Jean',
    variance_reason: 'erreur de comptage',
    labels: {
      ...sampleReceipt.labels,
      cash_count_section_title: 'COMPTAGE CAISSE',
      cash_count_total_variance: 'Écart total :',
      cash_count_approved_by: 'Validé par :',
      cash_count_reason: 'Motif :',
      cash_count_col_tender: 'Moyen',
      cash_count_col_expected: 'Attendu',
      cash_count_col_actual: 'Réel',
      cash_count_col_variance: 'Écart',
    },
  };

  it('prints the section title, an aligned table, the aggregate variance, the approver and the reason', () => {
    const lines = texts(buildReceiptDoc(zReceipt, display, print).segments);
    expect(lines).toContain('COMPTAGE CAISSE');
    expect(lines.some((line) => line.startsWith('Moyen') && line.includes('Attendu'))).toBe(true);
    expect(lines.some((line) => line.startsWith('Espèces') && line.trimEnd().endsWith('+'))).toBe(true);
    expect(lines).toContain('Écart total : 5.000 +');
    expect(lines).toContain('Validé par : Jean');
    expect(lines).toContain('Motif : erreur de comptage');
  });

  it('pads the tender column to cols/4 capped at 10 (receipt_template.rs:880)', () => {
    const row = texts(buildReceiptDoc(zReceipt, display, { ...print, columns: 32 }).segments).find(
      (line) => line.startsWith('Espèces'),
    );
    expect(row?.slice(0, 8)).toBe('Espèces ');
  });

  it('prints no cash-count block when the list is empty', () => {
    const lines = texts(buildReceiptDoc({ ...zReceipt, cash_counts: [] }, display, print).segments);
    expect(lines).not.toContain('COMPTAGE CAISSE');
  });
});

describe('buildReceiptDoc — tail', () => {
  it('always ends with a 4-line feed then the cut the device asked for', () => {
    const doc = buildReceiptDoc(sampleReceipt, display, print);
    expect(doc.segments.slice(-2)).toEqual([
      { kind: 'feed', lines: 4 },
      { kind: 'cut', mode: 'partial' },
    ]);
  });

  it('emits cut mode none when the device disables cutting', () => {
    const doc = buildReceiptDoc(sampleReceipt, display, { ...print, cutMode: 'none' });
    expect(doc.segments.at(-1)).toEqual({ kind: 'cut', mode: 'none' });
  });
});
```

`packages/shared/src/receipt/__tests__/buildReceiptDoc.snapshot.test.ts` — the matrix the spec §5 asks for (`{TTC,HT} × {logo on,off} × {42,32}`):

```ts
import { describe, expect, it } from 'vitest';
import { buildReceiptDoc } from '../buildReceiptDoc';
import { sampleReceipt } from '../fixtures/sampleReceipt';
import { padColumns } from '../padColumns';
import type { ReceiptColumns, ReceiptSubtotalMode } from '../index';

/** Render a document the way the preview and the printer both lay it out. */
function render(columns: ReceiptColumns, subtotalMode: ReceiptSubtotalMode, logo: boolean): string {
  const doc = buildReceiptDoc(
    sampleReceipt,
    {
      logo,
      subtotalMode,
      showVatBreakdown: true,
      showFiscalInfo: true,
      showPaymentDetails: true,
      showCustomer: true,
    },
    { columns, cutMode: 'partial', footerText: '' },
  );
  return doc.segments
    .map((segment) => {
      const padded = padColumns(segment, columns);
      if (padded !== null) return padded;
      switch (segment.kind) {
        case 'text':
          return segment.align === 'center'
            ? segment.text.padStart(Math.floor((columns + [...segment.text].length) / 2), ' ')
            : segment.text;
        case 'separator':
          return (segment.char ?? '-').repeat(columns);
        case 'blank':
          return '';
        case 'qr':
          return `[QR ${segment.label ?? ''} -> ${segment.payload}]`;
        case 'logo':
          return '[LOGO]';
        case 'feed':
          return `[FEED ${String(segment.lines)}]`;
        case 'cut':
          return `[CUT ${segment.mode}]`;
        case 'drawer-kick':
          return '[DRAWER]';
        default:
          return '';
      }
    })
    .join('\n');
}

describe('buildReceiptDoc snapshots', () => {
  for (const columns of [42, 32] as const) {
    for (const subtotalMode of ['TTC', 'HT'] as const) {
      for (const logo of [false, true]) {
        it(`${String(columns)} cols · ${subtotalMode} · logo ${logo ? 'on' : 'off'}`, () => {
          expect(render(columns, subtotalMode, logo)).toMatchSnapshot();
        });
      }
    }
  }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `pnpm --filter @autoerp/shared test`
Expected: FAIL — no footer, QR, cash-count or tail segments are produced yet.

- [ ] **Step 3: Write `appendFooter` and the cash-count formatters**

Append to `packages/shared/src/receipt/buildReceiptDoc.ts`:

```ts
/**
 * Port of `format_cash_count_header` (receipt_template.rs:879-894).
 *
 * The Rust original slices the column labels by BYTE index
 * (`&label[..len.min(9)]`); this port slices by code point, which cannot land
 * mid-character. For the shipped labels (`Attendu`, `Réel`, `Écart`) both
 * produce the same string.
 */
function formatCashCountHeader(ctx: BuildContext, columns: number): string {
  const tenderWidth = Math.min(Math.floor(columns / 4), 10);
  const tender = [...ctx.label((labels) => labels.cash_count_col_tender, 'Tender')]
    .slice(0, tenderWidth)
    .join('');
  const cell = (value: string): string => [...value].slice(0, 9).join('').padStart(9, ' ');
  return [
    tender.padEnd(tenderWidth, ' '),
    cell(ctx.label((labels) => labels.cash_count_col_expected, 'Expected')),
    cell(ctx.label((labels) => labels.cash_count_col_actual, 'Actual')),
    cell(ctx.label((labels) => labels.cash_count_col_variance, 'Variance')),
  ].join(' ');
}

/** Port of `format_cash_count_row` (receipt_template.rs:896-913). */
function formatCashCountRow(row: ZReceiptCashCountRow, columns: number): string {
  const tenderWidth = Math.min(Math.floor(columns / 4), 10);
  const direction = row.direction === 'over' ? '+' : row.direction === 'under' ? '-' : ' ';
  const name = [...row.name].slice(0, tenderWidth).join('').padEnd(tenderWidth, ' ');
  return `${name} ${row.expected.padStart(9, ' ')} ${row.actual.padStart(9, ' ')} ${row.variance.padStart(8, ' ')}${direction}`;
}

/** Notes, fiscal footer, QR, footer text, Z block, feed + cut — receipt_template.rs:730-865. */
function appendFooter(segments: ReceiptSegment[], ctx: BuildContext): void {
  const { data } = ctx;
  const columns = ctx.print.columns;

  if (data.notes !== null) {
    segments.push({ kind: 'separator', char: '-' });
    segments.push({ kind: 'text', text: data.notes });
  }

  // Fiscal compliance footer. The hash and the signature stay as TEXT; the
  // fiscal-hash QR that used to follow them (receipt_template.rs:760-764) is
  // dropped — it encoded data already printed one line above, carried no URL
  // and meant nothing to a customer (DEV-QA-086, owner ruling 6).
  if (ctx.display.showFiscalInfo && (data.fiscal_hash !== null || data.fiscal_signature !== null)) {
    segments.push({ kind: 'separator', char: '-' });
    if (data.fiscal_hash !== null) {
      const hash = data.fiscal_hash;
      const shown =
        hash.length > 32 ? `${hash.slice(0, 16)}...${hash.slice(hash.length - 16)}` : hash;
      segments.push({ kind: 'text', text: `Hash: ${shown}`, align: 'center' });
    }
    if (data.fiscal_signature !== null) {
      segments.push({ kind: 'text', text: `Sig: ${data.fiscal_signature}`, align: 'center' });
    }
  }

  // The ONE customer-facing QR: the signed `v:kid:receipt_uuid:mac` token any
  // terminal scans to start a return or an exchange. It now carries a caption
  // (`pos:receiptLabel.qrScanLabel`) — sale tickets previously printed it bare
  // while refunds already labelled theirs (receipt_template.rs:496-497).
  const token = nonEmptyTrimmed(data.qr_token);
  if (token !== null) {
    segments.push({ kind: 'separator', char: '-' });
    segments.push({ kind: 'blank' });
    segments.push({
      kind: 'qr',
      payload: token,
      moduleSize: 4,
      label: ctx.label((labels) => labels.qr_scan_label, 'Scan for return / exchange'),
    });
    segments.push({ kind: 'blank' });
    segments.push({ kind: 'text', text: token, align: 'center' });
  }

  segments.push({ kind: 'blank' });
  const footerText =
    ctx.print.footerText === ''
      ? ctx.label((labels) => labels.thank_you, 'Thank you for your purchase!')
      : ctx.print.footerText;
  segments.push({ kind: 'text', text: footerText, align: 'center' });
  segments.push({ kind: 'blank' });

  const counts = data.cash_counts ?? [];
  if (counts.length > 0) {
    segments.push({ kind: 'separator', char: '-' });
    segments.push({
      kind: 'text',
      text: ctx.label((labels) => labels.cash_count_section_title, 'CASH COUNT'),
      align: 'center',
      bold: true,
    });
    segments.push({ kind: 'text', text: formatCashCountHeader(ctx, columns) });
    segments.push({ kind: 'separator', char: '-' });
    for (const row of counts) {
      segments.push({ kind: 'text', text: formatCashCountRow(row, columns) });
    }
    segments.push({ kind: 'separator', char: '-' });

    const aggregate = data.aggregate_variance;
    if (aggregate !== null && aggregate !== undefined) {
      const direction = counts.some((row) => row.direction === 'over')
        ? '+'
        : counts.some((row) => row.direction === 'under')
          ? '-'
          : ' ';
      const label = ctx.label((labels) => labels.cash_count_total_variance, 'Total variance:');
      segments.push({ kind: 'text', text: `${label} ${aggregate} ${direction}` });
    }
    if (data.manager_name !== null && data.manager_name !== undefined) {
      const label = ctx.label((labels) => labels.cash_count_approved_by, 'Approved by:');
      segments.push({ kind: 'text', text: `${label} ${data.manager_name}` });
    }
    if (data.variance_reason !== null && data.variance_reason !== undefined) {
      const label = ctx.label((labels) => labels.cash_count_reason, 'Reason:');
      segments.push({ kind: 'text', text: `${label} ${data.variance_reason}` });
    }
  }

  segments.push({ kind: 'feed', lines: 4 });
  segments.push({ kind: 'cut', mode: ctx.print.cutMode });
}
```

Import `ZReceiptCashCountRow` in the type import at the top of the file:

```ts
import type { ReceiptData, ReceiptLabels, ZReceiptCashCountRow } from './receiptData';
```

Call it from `buildReceiptDoc`, after `appendBody`:

```ts
  const fallback = appendBody(segments, ctx);
  appendFooter(segments, ctx);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `pnpm --filter @autoerp/shared test`
Expected: PASS — 16 footer tests + 8 newly-written snapshots. Read the generated `__snapshots__/buildReceiptDoc.snapshot.test.ts.snap` line by line before committing: it is the human-readable record of the ticket this PR ships, and the four fixes must be visible in it (one matricule line, `11.000 TND`, one labelled QR, `Sous-total HT :` in the HT snapshots).

- [ ] **Step 5: Commit**

```bash
git add packages/shared/src/receipt
git commit -m "fix(pos-receipt DEV-QA-086): one labelled return QR, drop the fiscal-hash QR, Z block and tail"
```

---

### Task 7: POS wiring — `printReceipt` sends a `ReceiptDoc`, display settings come from `/company/config`

`printReceipt` (`apps/pos/src/lib/printing.ts:338-349`) invokes `print_receipt` with a raw `ReceiptData` today. It now builds the document on the device and invokes `print_receipt_doc`. **All six call sites keep their current signature** — `CheckoutSuccessModal.tsx:54`, `Header.tsx:657`, `refundFlow/refundReceiptPrinting.ts:97`, `RefundPayoutReconciliationModal.tsx:103`, `SettingsPage.tsx:178` (test page, a different command) and the Z path through `buildZReceiptData`.

Display settings ride the existing `/company/config` read (`apps/api/app/Http/Controllers/Api/CompanyConfigController.php:91-96` → `authStore.ts:420-427` → `useProductStore.companyConfig`); PR 2 adds `logo` and `subtotal_mode` to that payload. Until then — and against any older server — the resolver defaults to `logo: false, subtotalMode: 'TTC'`, so this PR is safe to ship alone.

**Files:**
- Modify: `apps/pos/src/lib/printing.ts:338-349` (`printReceipt`), imports at `:1-5`
- Create: `apps/pos/src/lib/receiptDisplaySettings.ts`
- Modify: `apps/pos/src/types/companyConfig.ts:1-6` (`ReceiptVisibility`)
- Test: `apps/pos/src/lib/__tests__/receiptDisplaySettings.test.ts`

**Interfaces:**
- Consumes: `buildReceiptDoc`, `ReceiptDoc`, `ReceiptDisplaySettings`, `ReceiptPrintContext`, `ReceiptColumns` (Tasks 1-6).
- Produces:
  - `resolveReceiptDisplaySettings(config?: CompanyConfig | null): ReceiptDisplaySettings`
  - `toPrintContext(settings: PrintSettings): ReceiptPrintContext`
  - `printReceipt(receipt: ReceiptData, printer: PrinterConfig, printSettings?: PrintSettings): Promise<void>` — unchanged signature, new Tauri payload `{ doc, connectionType, address, printSettings }` on command `print_receipt_doc`.
  - `ReceiptVisibility` gains `logo?: boolean` and `subtotal_mode?: string`.

- [ ] **Step 1: Write the failing test**

`apps/pos/src/lib/__tests__/receiptDisplaySettings.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { resolveReceiptDisplaySettings, toPrintContext } from '../receiptDisplaySettings';

describe('resolveReceiptDisplaySettings', () => {
  it('defaults everything visible, logo off and TTC when the device has no config yet', () => {
    expect(resolveReceiptDisplaySettings(null)).toEqual({
      logo: false,
      subtotalMode: 'TTC',
      showVatBreakdown: true,
      showFiscalInfo: true,
      showPaymentDetails: true,
      showCustomer: true,
    });
  });

  it('defaults logo and subtotal_mode when an OLDER server omits them', () => {
    const resolved = resolveReceiptDisplaySettings({
      all_enabled_modules: [],
      receipt_visibility: {
        show_vat_breakdown: true,
        show_fiscal_info: false,
        show_payment_details: true,
        show_customer: false,
      },
    });
    expect(resolved.logo).toBe(false);
    expect(resolved.subtotalMode).toBe('TTC');
    expect(resolved.showFiscalInfo).toBe(false);
    expect(resolved.showCustomer).toBe(false);
  });

  it('reads logo and subtotal_mode when the server sends them', () => {
    const resolved = resolveReceiptDisplaySettings({
      all_enabled_modules: [],
      receipt_visibility: {
        show_vat_breakdown: true,
        show_fiscal_info: true,
        show_payment_details: true,
        show_customer: true,
        logo: true,
        subtotal_mode: 'HT',
      },
    });
    expect(resolved.logo).toBe(true);
    expect(resolved.subtotalMode).toBe('HT');
  });

  it('degrades an unrecognised subtotal_mode to TTC instead of throwing (spec §3)', () => {
    const resolved = resolveReceiptDisplaySettings({
      all_enabled_modules: [],
      receipt_visibility: {
        show_vat_breakdown: true,
        show_fiscal_info: true,
        show_payment_details: true,
        show_customer: true,
        subtotal_mode: 'ht',
      },
    });
    expect(resolved.subtotalMode).toBe('TTC');
  });
});

describe('toPrintContext', () => {
  it('maps 42 and 32 columns straight through', () => {
    expect(toPrintContext({ columns: 42, cut_mode: 'partial', encoding: 'cp1252', footer_text: '', copies: 1 })).toEqual({
      columns: 42,
      cutMode: 'partial',
      footerText: '',
    });
    expect(toPrintContext({ columns: 32, cut_mode: 'none', encoding: 'cp858', footer_text: 'Bye', copies: 2 })).toEqual({
      columns: 32,
      cutMode: 'none',
      footerText: 'Bye',
    });
  });

  it('falls back to 42 for any other column count (the Rust default, receipt_template.rs:306)', () => {
    expect(
      toPrintContext({ columns: 48, cut_mode: 'full', encoding: 'cp1252', footer_text: '', copies: 1 }).columns,
    ).toBe(42);
  });
});
```

Extend `apps/pos/src/lib/__tests__/printing.test.ts` with an invoke-contract block (the Tauri `invoke` is mocked; follow the existing mocking style in `apps/pos/src/test/setup.ts`):

```ts
import { invoke } from '@tauri-apps/api/core';
import { printReceipt } from '../printing';
import { sampleReceipt } from '@autoerp/shared/src/receipt/fixtures/sampleReceipt';

vi.mock('@tauri-apps/api/core', () => ({ invoke: vi.fn().mockResolvedValue(undefined) }));

describe('printReceipt', () => {
  beforeEach(() => {
    vi.mocked(invoke).mockClear();
  });

  it('invokes print_receipt_doc with a ReceiptDoc, never the retired print_receipt', async () => {
    await printReceipt(
      sampleReceipt,
      { connection_type: 'usb', address: '/dev/ttyUSB0', name: 'TM-T20' },
      { columns: 42, cut_mode: 'partial', encoding: 'cp1252', footer_text: '', copies: 1 },
    );

    expect(vi.mocked(invoke).mock.calls).toHaveLength(1);
    const [command, payload] = vi.mocked(invoke).mock.calls[0]!;
    expect(command).toBe('print_receipt_doc');
    const body = payload as { doc: { version: number; columns: number; segments: unknown[] }; address: string };
    expect(body.doc.version).toBe(1);
    expect(body.doc.columns).toBe(42);
    expect(body.doc.segments.length).toBeGreaterThan(10);
    expect(body.address).toBe('/dev/ttyUSB0');
  });

  it('builds a 32-column document for a 58 mm printer', async () => {
    await printReceipt(
      sampleReceipt,
      { connection_type: 'network', address: '192.168.1.50:9100', name: 'Xprinter' },
      { columns: 32, cut_mode: 'partial', encoding: 'cp1252', footer_text: '', copies: 1 },
    );
    const [, payload] = vi.mocked(invoke).mock.calls[0]!;
    expect((payload as { doc: { columns: number } }).doc.columns).toBe(32);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `pnpm --filter @autoerp/pos vitest run src/lib/__tests__/receiptDisplaySettings.test.ts src/lib/__tests__/printing.test.ts`
Expected: FAIL — module `../receiptDisplaySettings` not found, and `printReceipt` still invokes `print_receipt`.

- [ ] **Step 3: Extend the device-side config type**

`apps/pos/src/types/companyConfig.ts` — replace lines 1-6:

```ts
export interface ReceiptVisibility {
  show_vat_breakdown: boolean;
  show_fiscal_info: boolean;
  show_payment_details: boolean;
  show_customer: boolean;
  /**
   * Print the company logo. Added by Lane E PR 2; absent on an older server,
   * where it defaults to false. Drives the PDF and the back-office preview —
   * the thermal raster is ticket E-1.
   */
  logo?: boolean;
  /**
   * `'TTC'` | `'HT'`. Added by Lane E PR 2; absent on an older server, where it
   * defaults to `'TTC'`. Anything unrecognised is treated as `'TTC'`.
   */
  subtotal_mode?: string;
}
```

- [ ] **Step 4: Write the resolver**

`apps/pos/src/lib/receiptDisplaySettings.ts` (new):

```ts
import type {
  ReceiptColumns,
  ReceiptCutMode,
  ReceiptDisplaySettings,
  ReceiptPrintContext,
  ReceiptSubtotalMode,
} from '@autoerp/shared/src/receipt';
import type { CompanyConfig } from '@/types/companyConfig';
import type { PrintSettings } from '@/lib/printing';
import { useProductStore } from '@/stores/productStore';

/**
 * Display settings for the printed ticket, read off the company config the
 * device already fetches (authStore.ts:420-427 -> useProductStore.companyConfig,
 * server side CompanyConfigController.php:91-96).
 *
 * Every field defaults so that a device talking to a server without the Lane E
 * columns prints exactly today's ticket: all sections visible, no logo, TTC.
 */
export function resolveReceiptDisplaySettings(
  config?: CompanyConfig | null,
): ReceiptDisplaySettings {
  const visibility = config?.receipt_visibility;
  const rawMode = visibility?.subtotal_mode;
  const subtotalMode: ReceiptSubtotalMode = rawMode === 'HT' ? 'HT' : 'TTC';

  return {
    logo: visibility?.logo ?? false,
    subtotalMode,
    showVatBreakdown: visibility?.show_vat_breakdown ?? true,
    showFiscalInfo: visibility?.show_fiscal_info ?? true,
    showPaymentDetails: visibility?.show_payment_details ?? true,
    showCustomer: visibility?.show_customer ?? true,
  };
}

/** Non-hook accessor, for the print path (which runs outside React). */
export function currentReceiptDisplaySettings(): ReceiptDisplaySettings {
  return resolveReceiptDisplaySettings(useProductStore.getState().companyConfig);
}

/**
 * Narrow the device print settings to the document's print context. Any column
 * count other than 32 falls back to 42 — the same default the Rust template
 * used when no settings were supplied (receipt_template.rs:306).
 */
export function toPrintContext(settings: PrintSettings): ReceiptPrintContext {
  const columns: ReceiptColumns = settings.columns === 32 ? 32 : 42;
  const cutMode: ReceiptCutMode =
    settings.cut_mode === 'full' ? 'full' : settings.cut_mode === 'none' ? 'none' : 'partial';
  return { columns, cutMode, footerText: settings.footer_text };
}
```

- [ ] **Step 5: Rewrite `printReceipt`**

`apps/pos/src/lib/printing.ts` — replace lines 338-349 (the whole `printReceipt` function and its doc comment):

```ts
/**
 * Print a receipt to the specified printer.
 *
 * The LAYOUT is decided here, in TypeScript (`buildReceiptDoc`), and the Rust
 * side only encodes the resulting document to ESC/POS bytes — which is what
 * lets the back-office preview show the identical ticket (spec 2026-09-17).
 *
 * The signature is unchanged so the six existing call sites are untouched.
 */
export async function printReceipt(
  receipt: ReceiptData,
  printer: PrinterConfig,
  printSettings?: PrintSettings,
): Promise<void> {
  const settings = printSettings ?? getPrintSettingsFromStore();
  const doc = buildReceiptDoc(
    receipt,
    currentReceiptDisplaySettings(),
    toPrintContext(settings),
  );

  return invoke<void>('print_receipt_doc', {
    doc,
    connectionType: printer.connection_type,
    address: printer.address,
    printSettings: settings,
  });
}
```

Add to the imports at the top of `apps/pos/src/lib/printing.ts`:

```ts
import { buildReceiptDoc } from '@autoerp/shared/src/receipt';
import {
  currentReceiptDisplaySettings,
  toPrintContext,
} from '@/lib/receiptDisplaySettings';
```

`printSettings` is still forwarded: the Rust side needs `encoding` (code page) and `copies`, which are device facts and not part of the document.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `pnpm --filter @autoerp/pos test` → PASS (whole POS suite).
Run: `pnpm --filter @autoerp/pos typecheck` → PASS.
Run: `pnpm --filter @autoerp/pos lint` → PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/pos/src/lib/printing.ts apps/pos/src/lib/receiptDisplaySettings.ts \
        apps/pos/src/types/companyConfig.ts apps/pos/src/lib/__tests__
git commit -m "feat(pos-print): printReceipt sends a ReceiptDoc and resolves display settings from /company/config"
```

---

### Task 8: Rust — always emit `ESC t`, make the transcoding match the declared code page, real CP858, test page takes `PrintSettings`

Fixes **DEV-QA-087** (P1). The four root causes, all cited from the Phase-1 trace:
1. `receipt_template.rs:312-315` sends `ESC t` only when `code_page() != 0`, so the `cp437` option never selects CP437 and never resets a printer left on another page.
2. `receipt_template.rs:274-280` returns `WINDOWS_1252` for **all three** options, so the byte stream is always CP1252 while the declared page is one of three values, two of which disagree.
3. `receipt_template.rs:921-1010` + `commands/printing.rs:107-112`: the operator's alignment/test page takes no `PrintSettings` at all, so the one page used to validate a printer cannot reveal the mismatch.
4. `escpos.rs:104,117` hardcode a default encoding with no matching `ESC t`.

CP437 cannot represent `é`/`è`/`ç`, so its arm is honest ASCII with `?` for everything else (spec §4.3). CP858 gets the real table `encoding_rs` does not provide. CP1252 + `ESC t 16` is the only pairing that prints French accents on the Epson TM / Xprinter families sold in Tunisia — it stays the device default (`printerStore.ts:35`).

**This task writes Rust and does NOT compile it** (Global Constraints: one cargo run, at Task 10).

**Files:**
- Modify: `apps/pos/src-tauri/src/printing/escpos.rs:86-132` (builder field + encoder), `:308-311` (`set_code_page` doc), `:533-541` (`test_set_encoding`)
- Modify: `apps/pos/src-tauri/src/printing/receipt_template.rs:263-280` (`code_page` / `encoding_rs` → `text_encoding`), `:917-925` (`format_test_page*` signature), `:926-931` (prologue)
- Modify: `apps/pos/src-tauri/src/commands/printing.rs:105-123` (`print_test_page`)
- Modify: `apps/pos/src/lib/printing.ts:396-408` (`printTestPage`)
- Modify: `apps/pos/src/pages/SettingsPage.tsx:178`

**Interfaces:**
- Produces:
  - `pub enum TextEncoding { Cp437, Cp858, Cp1252 }` in `printing::escpos`, with `pub fn code_page(self) -> u8` and `fn encode(self, s: &str) -> Vec<u8>`
  - `EscPosBuilder::set_encoding(&mut self, encoding: TextEncoding) -> &mut Self` (signature change)
  - `PrintSettings::text_encoding(&self) -> TextEncoding` (replaces `code_page` and `encoding_rs`)
  - `format_test_page_with_columns(columns: Option<u8>, settings: Option<&PrintSettings>) -> Vec<u8>`
  - Tauri command `print_test_page(connection_type, address, columns: Option<u8>, print_settings: Option<PrintSettings>)`
  - TS `printTestPage(printer: PrinterConfig, columns?: number, printSettings?: PrintSettings): Promise<void>`

- [ ] **Step 1: Write the failing Rust tests**

Append to the `mod tests` block in `apps/pos/src-tauri/src/printing/escpos.rs` (after `test_set_encoding`, ~line 541):

```rust
    #[test]
    fn cp437_encodes_ascii_and_replaces_accents_with_a_question_mark() {
        let mut builder = EscPosBuilder::new();
        builder.set_encoding(TextEncoding::Cp437);
        builder.text("Cafe crème");
        let data = builder.build();
        // "Cafe cr" passes through; è is unrepresentable in CP437 -> '?'.
        assert_eq!(&data[2..], b"Cafe cr?me");
    }

    #[test]
    fn cp858_encodes_french_accents_with_the_cp850_table() {
        let mut builder = EscPosBuilder::new();
        builder.set_encoding(TextEncoding::Cp858);
        builder.text("Café crème Garçon");
        let data = builder.build();
        // CP850/858: é=0x82, è=0x8A, ç=0x87.
        assert_eq!(
            &data[2..],
            &[
                0x43, 0x61, 0x66, 0x82, 0x20, 0x63, 0x72, 0x8A, 0x6D, 0x65, 0x20, 0x47, 0x61,
                0x72, 0x87, 0x6F, 0x6E
            ]
        );
    }

    #[test]
    fn cp858_maps_the_euro_sign_to_0xd5() {
        let mut builder = EscPosBuilder::new();
        builder.set_encoding(TextEncoding::Cp858);
        builder.text("€");
        assert_eq!(&builder.build()[2..], &[0xD5]);
    }

    #[test]
    fn cp1252_keeps_the_bytes_the_repo_already_asserts() {
        let mut builder = EscPosBuilder::new();
        builder.set_encoding(TextEncoding::Cp1252);
        builder.text("Café crème");
        assert_eq!(
            &builder.build()[2..],
            &[0x43, 0x61, 0x66, 0xE9, 0x20, 0x63, 0x72, 0xE8, 0x6D, 0x65]
        );
    }

    #[test]
    fn every_encoding_declares_the_code_page_the_printer_needs() {
        assert_eq!(TextEncoding::Cp437.code_page(), 0);
        assert_eq!(TextEncoding::Cp1252.code_page(), 16);
        assert_eq!(TextEncoding::Cp858.code_page(), 19);
    }
```

Append to `apps/pos/src-tauri/src/printing/receipt_template.rs`, as a new test module at the end of the file:

```rust
#[cfg(test)]
mod tests_encoding_contract {
    use super::*;
    use crate::printing::escpos::TextEncoding;

    fn settings(encoding: &str) -> PrintSettings {
        PrintSettings {
            columns: 42,
            cut_mode: "partial".to_string(),
            encoding: encoding.to_string(),
            footer_text: String::new(),
            copies: 1,
        }
    }

    #[test]
    fn the_transcoding_always_matches_the_declared_code_page() {
        assert_eq!(settings("cp437").text_encoding(), TextEncoding::Cp437);
        assert_eq!(settings("cp858").text_encoding(), TextEncoding::Cp858);
        assert_eq!(settings("cp1252").text_encoding(), TextEncoding::Cp1252);
        // An unknown value is the device default, not a silent CP437.
        assert_eq!(settings("klingon").text_encoding(), TextEncoding::Cp1252);
    }

    #[test]
    fn esc_t_is_emitted_even_for_cp437_so_a_printer_left_on_another_page_is_reset() {
        let bytes = format_test_page_with_columns(Some(42), Some(&settings("cp437")));
        // ESC @ then ESC t 0.
        assert_eq!(&bytes[0..2], &[0x1B, 0x40]);
        assert_eq!(&bytes[2..5], &[0x1B, 0x74, 0x00]);
    }

    #[test]
    fn the_operator_test_page_declares_the_configured_code_page() {
        let bytes = format_test_page_with_columns(Some(42), Some(&settings("cp1252")));
        assert_eq!(&bytes[2..5], &[0x1B, 0x74, 0x10]);

        let bytes = format_test_page_with_columns(Some(32), Some(&settings("cp858")));
        assert_eq!(&bytes[2..5], &[0x1B, 0x74, 0x13]);
    }

    #[test]
    fn the_test_page_prints_the_accent_probe_so_a_mismatch_is_visible_on_paper() {
        let bytes = format_test_page_with_columns(Some(42), Some(&settings("cp1252")));
        // "Café crème Garçon" in CP1252.
        let probe = [0x43u8, 0x61, 0x66, 0xE9, 0x20, 0x63, 0x72, 0xE8, 0x6D, 0x65];
        assert!(
            bytes.windows(probe.len()).any(|window| window == probe),
            "the test page must print the accent probe"
        );
    }

    #[test]
    fn a_test_page_without_settings_keeps_todays_default() {
        let bytes = format_test_page_with_columns(Some(42), None);
        assert_eq!(&bytes[2..5], &[0x1B, 0x74, 0x10]);
    }
}
```

- [ ] **Step 2: Note why the tests are not run now**

These tests compile and run only in the Task 10 cargo invocation. Do **not** run `cargo test` here (Global Constraints). Record in the task's commit body: *"Rust written, not compiled — proven by the single cargo run in Task 10."*

- [ ] **Step 3: Add `TextEncoding` to `escpos.rs`**

Insert after the `QrErrorCorrection` impl (~line 84), before `pub struct EscPosBuilder`:

```rust
/// Character encoding for text output, paired with the `ESC t n` code page
/// the printer must be told to select.
///
/// Before Lane E these two were decoupled: `PrintSettings::encoding_rs`
/// returned WINDOWS_1252 for all three options while `code_page` returned
/// 0/16/19, and `ESC t` was suppressed entirely for page 0 — so a terminal set
/// to `cp437`, or a clone that ignores `ESC t 16`, printed `é` as `Θ` and `ç`
/// as `τ` (DEV-QA-087).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TextEncoding {
    /// Epson power-on page. ASCII only — accents are NOT representable and
    /// become `?`, which is honest: the alternative is silent mojibake.
    Cp437,
    /// CP850 + the euro sign at 0xD5. `encoding_rs` has no CP850, so the
    /// Latin-1 range is mapped by the table below.
    Cp858,
    /// WPC1252. The only pairing that prints French accents on the Epson TM /
    /// Xprinter families; the device default (printerStore.ts:35).
    Cp1252,
}

/// CP850/CP858 byte for every code point U+00A0..=U+00FF, indexed by
/// `c as usize - 0xA0`. `€` (U+20AC) is handled separately: CP858 replaces
/// CP850's `ı` at 0xD5 with it.
const CP858_LATIN1: [u8; 96] = [
    0xFF, 0xAD, 0xBD, 0x9C, 0xCF, 0xBE, 0xDD, 0xF5, 0xF9, 0xB8, 0xA6, 0xAE, 0xAA, 0xF0, 0xA9,
    0xEE, 0xF8, 0xF1, 0xFD, 0xFC, 0xEF, 0xE6, 0xF4, 0xFA, 0xF7, 0xFB, 0xA7, 0xAF, 0xAC, 0xAB,
    0xF3, 0xA8, 0xB7, 0xB5, 0xB6, 0xC7, 0x8E, 0x8F, 0x92, 0x80, 0xD4, 0x90, 0xD2, 0xD3, 0xDE,
    0xD6, 0xD7, 0xD8, 0xD1, 0xA5, 0xE3, 0xE0, 0xE2, 0xE5, 0x99, 0x9E, 0x9D, 0xEB, 0xE9, 0xEA,
    0x9A, 0xED, 0xE8, 0xE1, 0x85, 0xA0, 0x83, 0xC6, 0x84, 0x86, 0x91, 0x87, 0x8A, 0x82, 0x88,
    0x89, 0x8D, 0xA1, 0x8C, 0x8B, 0xD0, 0xA4, 0x95, 0xA2, 0x93, 0xE4, 0x94, 0xF6, 0x9B, 0x97,
    0xA3, 0x96, 0x81, 0xEC, 0xE7, 0x98,
];

impl TextEncoding {
    /// The `ESC t n` argument this encoding requires.
    pub fn code_page(self) -> u8 {
        match self {
            TextEncoding::Cp437 => 0,
            TextEncoding::Cp1252 => 16,
            TextEncoding::Cp858 => 19,
        }
    }

    /// Encode UTF-8 text into this code page. Unrepresentable characters
    /// become `?` (0x3F) — never a panic, never a silent wrong glyph.
    fn encode(self, s: &str) -> Vec<u8> {
        match self {
            TextEncoding::Cp1252 => {
                let (cow, _encoding_used, _had_errors) = encoding_rs::WINDOWS_1252.encode(s);
                cow.into_owned()
            }
            TextEncoding::Cp437 => s
                .chars()
                .map(|c| if c.is_ascii() { c as u8 } else { b'?' })
                .collect(),
            TextEncoding::Cp858 => s
                .chars()
                .map(|c| match c {
                    c if c.is_ascii() => c as u8,
                    '\u{20AC}' => 0xD5,
                    c if ('\u{00A0}'..='\u{00FF}').contains(&c) => {
                        CP858_LATIN1[(c as usize) - 0xA0]
                    }
                    _ => b'?',
                })
                .collect(),
        }
    }
}
```

- [ ] **Step 4: Switch `EscPosBuilder` onto `TextEncoding`**

In `apps/pos/src-tauri/src/printing/escpos.rs`:
- line 93 — replace the struct field `encoding: &'static encoding_rs::Encoding,` with `encoding: TextEncoding,`
- lines 107 and 118 — replace `encoding: encoding_rs::WINDOWS_1252,` with `encoding: TextEncoding::Cp1252,`
- lines 121-125 — replace `set_encoding`:

```rust
    /// Set the character encoding used for text output. The caller is
    /// responsible for emitting the matching `ESC t` (see `set_code_page`);
    /// `doc_encoder::encode` always does both, in that order.
    pub fn set_encoding(&mut self, encoding: TextEncoding) -> &mut Self {
        self.encoding = encoding;
        self
    }
```

- lines 127-132 — replace `encode_text`:

```rust
    /// Encode a UTF-8 string into the target code page bytes.
    /// Characters not representable in the target encoding become `?`.
    fn encode_text(&self, s: &str) -> Vec<u8> {
        self.encoding.encode(s)
    }
```

- line 537 in `test_set_encoding` — replace `builder.set_encoding(encoding_rs::WINDOWS_1252);` with `builder.set_encoding(TextEncoding::Cp1252);`

- [ ] **Step 5: Replace `code_page` / `encoding_rs` on `PrintSettings`**

In `apps/pos/src-tauri/src/printing/receipt_template.rs`, replace lines 263-280 (the `code_page` and `encoding_rs` methods) with:

```rust
    /// Encoding AND code page, resolved together so they can never disagree
    /// (DEV-QA-087). An unrecognised value is the device default, CP1252 —
    /// the pairing that prints French accents on the terminals in the field.
    pub(crate) fn text_encoding(&self) -> TextEncoding {
        match self.encoding.as_str() {
            "cp437" => TextEncoding::Cp437,
            "cp858" => TextEncoding::Cp858,
            _ => TextEncoding::Cp1252,
        }
    }
```

and extend the module import at line 3:

```rust
use super::escpos::{Alignment, CutMode, EscPosBuilder, FontSize, QrErrorCorrection, TextEncoding};
```

Add a `Default` impl beside it, which `doc_encoder` uses when a caller sends no settings:

```rust
impl Default for PrintSettings {
    fn default() -> Self {
        Self {
            columns: 42,
            cut_mode: "partial".to_string(),
            encoding: "cp1252".to_string(),
            footer_text: String::new(),
            copies: 1,
        }
    }
}
```

- [ ] **Step 6: Give the test page its `PrintSettings`**

In `apps/pos/src-tauri/src/printing/receipt_template.rs`, replace lines 915-931 (`format_test_page`, `format_test_page_with_columns` signature and prologue):

```rust
/// Format a test page for printer alignment verification.
pub fn format_test_page() -> Vec<u8> {
    format_test_page_with_columns(None, None)
}

/// Format a test page with optional column width and print settings.
///
/// The settings matter: before Lane E this page emitted no code page at all,
/// so the ONE page an operator prints to validate a printer could not reveal
/// the encoding mismatch behind DEV-QA-087.
pub fn format_test_page_with_columns(
    columns: Option<u8>,
    settings: Option<&PrintSettings>,
) -> Vec<u8> {
    let cols = columns.unwrap_or(42);
    let encoding = settings.map_or(TextEncoding::Cp1252, |s| s.text_encoding());
    let mut b = EscPosBuilder::with_columns(cols);
    b.set_encoding(encoding);
    b.set_code_page(encoding.code_page());
```

Then, inside the same function, insert the accent probe immediately after the `b.text_line("Printer OK");` line (`receipt_template.rs:993`):

```rust
    b.text_line("Café crème Garçon - à é è ê ç ù €");
```

- [ ] **Step 7: Thread the settings through the command and the TS wrapper**

`apps/pos/src-tauri/src/commands/printing.rs` — replace lines 105-123:

```rust
/// Print a test/alignment page to verify printer configuration.
#[tauri::command]
pub async fn print_test_page(
    connection_type: PrinterConnectionType,
    address: String,
    columns: Option<u8>,
    print_settings: Option<PrintSettings>,
) -> Result<(), PrintError> {
    let data = receipt_template::format_test_page_with_columns(columns, print_settings.as_ref());

    log::info!(
        "Printing test page ({} bytes, {} cols, encoding {}) to {:?}:{}",
        data.len(),
        columns.unwrap_or(42),
        print_settings.as_ref().map_or("cp1252", |s| s.encoding.as_str()),
        connection_type,
        address,
    );

    printing::send_to_printer(&connection_type, &address, &data).await
}
```

`apps/pos/src/lib/printing.ts` — replace `printTestPage` (lines 396-408):

```ts
/**
 * Print a test/alignment page to verify printer setup.
 *
 * The print settings are forwarded so the page declares the configured code
 * page and prints the accent probe: this is the page an operator uses to see
 * whether the printer speaks CP1252 (DEV-QA-087).
 */
export async function printTestPage(
  printer: PrinterConfig,
  columns?: number,
  printSettings?: PrintSettings,
): Promise<void> {
  return invoke<void>('print_test_page', {
    connectionType: printer.connection_type,
    address: printer.address,
    columns: columns ?? null,
    printSettings: printSettings ?? null,
  });
}
```

`apps/pos/src/pages/SettingsPage.tsx:178` — replace `await printTestPage(printerConfig, ps.columns);` with:

```tsx
      await printTestPage(printerConfig, ps.columns, ps);
```

- [ ] **Step 8: Verify the TypeScript half**

Run: `pnpm --filter @autoerp/pos typecheck` → PASS.
Run: `pnpm --filter @autoerp/pos test` → PASS.

- [ ] **Step 9: Commit**

```bash
git add apps/pos/src-tauri/src/printing/escpos.rs apps/pos/src-tauri/src/printing/receipt_template.rs \
        apps/pos/src-tauri/src/commands/printing.rs apps/pos/src/lib/printing.ts apps/pos/src/pages/SettingsPage.tsx
git commit -m "fix(pos-escpos DEV-QA-087): always emit ESC t, pair transcoding with the code page, real CP858, test page takes PrintSettings

Rust written, not compiled — proven by the single cargo run in Task 10."
```

---

### Task 9: Rust — `doc_encoder`, `print_receipt_doc`, and the retirement of `print_receipt` / `format_receipt_with_settings`

Rust stops deciding layout. `doc_encoder::encode` maps each segment onto the existing `EscPosBuilder` primitives (`escpos.rs:141-204`, `:213-298`, `:335-381`) into **one** buffer — the Windows transport writes a single RAW spooler job (`printing/mod.rs:64-69`), so segment-per-write is not an option. The copies loop stays where it is (`commands/printing.rs:49,60-62`).

`format_receipt_with_settings` (`receipt_template.rs:302-868`) and the `print_receipt` command are deleted so no second print path survives (convention 11). The function is **moved verbatim**, with the `ReceiptData` family it consumes and its 14 layout tests, into `printing/golden_reference.rs` behind `#[cfg(test)]`: that frozen copy is what makes Task 10's golden-bytes regression possible inside ONE cargo run, and its 14 surviving tests are the tamper test proving the reference itself has not been edited (convention 08).

**This task writes Rust and does NOT compile it.**

**Files:**
- Create: `apps/pos/src-tauri/src/printing/doc_encoder.rs`
- Create: `apps/pos/src-tauri/src/printing/golden_reference.rs`
- Modify: `apps/pos/src-tauri/src/printing/mod.rs:1-7` (module list)
- Modify: `apps/pos/src-tauri/src/printing/receipt_template.rs` — delete `:16-124` (`ReceiptData`), `:126-182` (`ReceiptLabels`), `:184-193` (`impl ReceiptData::label`), `:216-243` (`ReceiptLine`, `ModifierLine`, `VatBreakdownLine`, `PaymentLine`), `:4-14` (`ZReceiptCashCountRow`), `:296-299` (`format_receipt`), `:300-868` (`format_receipt_with_settings`), `:870-913` (`non_empty_trimmed`, `format_cash_count_header`, `format_cash_count_row`) and `:1012-1704` (`mod tests_z_cash_counts`). **Keep** `CompanyInfo` (`:195-214`, used by `voucher_ticket.rs:23`), `PrintSettings`, `DrawerKickSettings`, `format_test_page*`, `format_drawer_kick*` and `mod tests_encoding_contract` from Task 8.
- Modify: `apps/pos/src-tauri/src/commands/printing.rs:1-5` (imports), `:36-65` (`print_receipt` → `print_receipt_doc`)
- Modify: `apps/pos/src-tauri/src/lib.rs:31` (`commands::printing::print_receipt` → `print_receipt_doc`)

**Interfaces:**
- Consumes: `TextEncoding`, `PrintSettings::text_encoding`, `PrintSettings::default` (Task 8); the JSON shape of `ReceiptDoc` (Task 1).
- Produces:
  - `pub struct ReceiptDoc { pub version: u8, pub columns: u8, pub segments: Vec<ReceiptSegment>, pub fallback: Option<String> }` in `printing::doc_encoder`
  - `pub enum ReceiptSegment` — internally tagged on `kind`, kebab-case variants, `Unknown` catch-all
  - `pub fn encode(doc: &ReceiptDoc, settings: &PrintSettings) -> Vec<u8>`
  - Tauri command `print_receipt_doc(doc: ReceiptDoc, connection_type: PrinterConnectionType, address: String, print_settings: Option<PrintSettings>) -> Result<(), PrintError>`
  - `#[cfg(test)] golden_reference::format_receipt_with_settings(data: &golden_reference::ReceiptData, settings: Option<&PrintSettings>) -> Vec<u8>`

- [ ] **Step 1: Write `doc_encoder.rs`**

```rust
//! ESC/POS encoder for the canonical `ReceiptDoc` (spec 2026-09-17, Lane E).
//!
//! Layout is decided ONCE, in TypeScript
//! (`packages/shared/src/receipt/buildReceiptDoc.ts`). This module only turns
//! the resulting segments into bytes, which is what lets the back-office
//! preview render the identical ticket. It replaces
//! `receipt_template::format_receipt_with_settings`, deleted in the same
//! commit; a frozen copy survives in `golden_reference.rs` for the
//! golden-bytes regression.
//!
//! Everything is appended to ONE buffer: the Windows transport performs a
//! single RAW spooler write (`printing/mod.rs:64-69`).

use serde::Deserialize;

use super::escpos::{Alignment, CutMode, EscPosBuilder, FontSize, QrErrorCorrection};
use super::receipt_template::PrintSettings;

#[derive(Debug, Clone, Copy, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum DocAlign {
    Left,
    Center,
    Right,
}

impl DocAlign {
    fn to_alignment(self) -> Alignment {
        match self {
            DocAlign::Left => Alignment::Left,
            DocAlign::Center => Alignment::Center,
            DocAlign::Right => Alignment::Right,
        }
    }
}

#[derive(Debug, Clone, Copy, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "kebab-case")]
pub enum DocSize {
    Normal,
    DoubleHeight,
    DoubleWidth,
    Double,
}

impl DocSize {
    fn to_font_size(self) -> FontSize {
        match self {
            DocSize::Normal => FontSize::Normal,
            DocSize::DoubleHeight => FontSize::DoubleHeight,
            DocSize::DoubleWidth => FontSize::DoubleWidth,
            DocSize::Double => FontSize::DoubleWidthHeight,
        }
    }
}

#[derive(Debug, Clone, Copy, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum DocCutMode {
    Full,
    Partial,
    None,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(tag = "kind", rename_all = "kebab-case")]
pub enum ReceiptSegment {
    Text {
        text: String,
        #[serde(default)]
        align: Option<DocAlign>,
        #[serde(default)]
        bold: Option<bool>,
        #[serde(default)]
        size: Option<DocSize>,
    },
    TwoColumn {
        left: String,
        right: String,
        #[serde(default)]
        bold: Option<bool>,
        #[serde(default)]
        size: Option<DocSize>,
    },
    ThreeColumn {
        left: String,
        middle: String,
        right: String,
        #[serde(default)]
        bold: Option<bool>,
    },
    Separator {
        #[serde(default, rename = "char")]
        separator_char: Option<String>,
    },
    Blank,
    Feed {
        lines: u8,
    },
    Cut {
        mode: DocCutMode,
    },
    Qr {
        payload: String,
        #[serde(rename = "moduleSize")]
        module_size: u8,
        #[serde(default)]
        label: Option<String>,
    },
    /// Placeholder. No raster command exists in escpos.rs, so nothing is
    /// emitted — the logo prints on the PDF and shows in the preview only
    /// (owner ruling 5; thermal raster is ticket E-1).
    Logo,
    DrawerKick,
    /// A segment kind this build does not know. Skipped with a warning rather
    /// than refused: a newer web build must never brick a till (spec §3).
    #[serde(other)]
    Unknown,
}

#[derive(Debug, Clone, Deserialize)]
pub struct ReceiptDoc {
    pub version: u8,
    pub columns: u8,
    pub segments: Vec<ReceiptSegment>,
    #[serde(default)]
    pub fallback: Option<String>,
}

/// Encode a document to ESC/POS bytes.
///
/// `doc.columns` is the authority for width — the document was BUILT at that
/// width, so re-reading `settings.columns` here could pad rows that the
/// builder already laid out. `settings` contributes the code page (which the
/// document does not carry, being device-local) and nothing else.
pub fn encode(doc: &ReceiptDoc, settings: &PrintSettings) -> Vec<u8> {
    let mut b = EscPosBuilder::with_columns(doc.columns);

    // Prologue: declare the code page ALWAYS, and transcode to the matching
    // encoding (DEV-QA-087). `with_columns` has already emitted ESC @.
    let encoding = settings.text_encoding();
    b.set_encoding(encoding);
    b.set_code_page(encoding.code_page());

    for segment in &doc.segments {
        encode_segment(&mut b, segment);
    }

    b.build()
}

fn encode_segment(b: &mut EscPosBuilder, segment: &ReceiptSegment) {
    match segment {
        ReceiptSegment::Text {
            text,
            align,
            bold,
            size,
        } => {
            let styled = apply_style(b, *align, *bold, *size);
            b.text_line(text);
            reset_style(b, styled);
        }
        ReceiptSegment::TwoColumn {
            left,
            right,
            bold,
            size,
        } => {
            let styled = apply_style(b, None, *bold, *size);
            b.two_column(left, right);
            reset_style(b, styled);
        }
        ReceiptSegment::ThreeColumn {
            left,
            middle,
            right,
            bold,
        } => {
            let styled = apply_style(b, None, *bold, None);
            b.three_column(left, middle, right);
            reset_style(b, styled);
        }
        ReceiptSegment::Separator { separator_char } => {
            let ch = separator_char
                .as_ref()
                .and_then(|s| s.chars().next())
                .unwrap_or('-');
            b.separator(ch);
        }
        ReceiptSegment::Blank => {
            b.empty_line();
        }
        ReceiptSegment::Feed { lines } => {
            b.feed_lines(*lines);
        }
        ReceiptSegment::Cut { mode } => match mode {
            DocCutMode::Full => {
                b.cut(CutMode::Full);
            }
            DocCutMode::Partial => {
                b.cut(CutMode::Partial);
            }
            DocCutMode::None => {}
        },
        ReceiptSegment::Qr {
            payload,
            module_size,
            label,
        } => {
            b.align(Alignment::Center);
            if let Some(caption) = label {
                if !caption.is_empty() {
                    b.select_font(true); // Font B for the caption
                    b.text_line(caption);
                    b.select_font(false);
                }
            }
            b.qr_code(payload, *module_size, QrErrorCorrection::M);
            b.align(Alignment::Left);
        }
        ReceiptSegment::Logo => {
            log::debug!("receipt logo segment skipped: no raster command (ticket E-1)");
        }
        ReceiptSegment::DrawerKick => {
            b.cash_drawer_kick(0);
        }
        ReceiptSegment::Unknown => {
            log::warn!("unknown receipt segment kind skipped by this build");
        }
    }
}

/// Which style toggles were switched on for a segment, so only those are reset.
#[derive(Debug, Clone, Copy, Default)]
struct AppliedStyle {
    align: bool,
    bold: bool,
    size: bool,
}

fn apply_style(
    b: &mut EscPosBuilder,
    align: Option<DocAlign>,
    bold: Option<bool>,
    size: Option<DocSize>,
) -> AppliedStyle {
    let mut applied = AppliedStyle::default();

    if let Some(value) = align {
        if value != DocAlign::Left {
            b.align(value.to_alignment());
            applied.align = true;
        }
    }
    if let Some(value) = size {
        if value != DocSize::Normal {
            b.font_size(value.to_font_size());
            applied.size = true;
        }
    }
    if bold == Some(true) {
        b.bold(true);
        applied.bold = true;
    }

    applied
}

fn reset_style(b: &mut EscPosBuilder, applied: AppliedStyle) {
    if applied.bold {
        b.bold(false);
    }
    if applied.size {
        b.font_size(FontSize::Normal);
    }
    if applied.align {
        b.align(Alignment::Left);
    }
}
```

- [ ] **Step 2: Freeze the old template**

Create `apps/pos/src-tauri/src/printing/golden_reference.rs`:

```rust
//! FROZEN copy of the pre-Lane-E receipt template.
//!
//! This is `receipt_template::format_receipt_with_settings` as it stood on
//! `origin/dev` 0b20e28dc, together with the `ReceiptData` family it consumed
//! and its layout tests, moved here verbatim and compiled ONLY under `cfg(test)`.
//!
//! Why it exists: the golden-bytes regression (doc_encoder tests) must compare
//! the NEW encoder against the OLD template, and the laptop budget allows a
//! single `cargo test` run for the whole PR — so the reference has to live in
//! the same build as the new code rather than on a previous checkout.
//!
//! DO NOT EDIT. The 14 tests at the bottom of this file are its tamper test
//! (convention 08): if someone "fixes" the reference, they fail.
```

Then move, **without changing a character of their bodies**:
- `ZReceiptCashCountRow` (`receipt_template.rs:4-14`), `ReceiptData` (`:16-124`), `ReceiptLabels` (`:126-182`), `impl ReceiptData { fn label }` (`:184-193`), `ReceiptLine` / `ModifierLine` / `VatBreakdownLine` / `PaymentLine` (`:216-243`)
- `format_receipt` (`:296-299`) and `format_receipt_with_settings` (`:300-868`)
- `non_empty_trimmed` (`:870-877`), `format_cash_count_header` (`:879-894`), `format_cash_count_row` (`:896-913`)
- `mod tests_z_cash_counts` (`:1012-1704`) — all 14 tests: `z_receipt_includes_cash_count_block_when_present`, `account_payment_receipt_uses_account_layout_without_sale_lines_or_vat`, `receipt_header_omits_blank_optional_legal_fields`, `receipt_header_trims_present_optional_legal_fields`, `receipt_header_prints_branch_vat_number_and_legal_identifier_lines`, `rounded_sale_prints_a_rounding_line_that_reconciles_the_printed_total`, `the_rounding_line_prints_between_tax_and_total_not_below_the_payments`, `a_discounted_sale_prints_the_ventilation_above_the_total_and_no_aggregate_tax_line`, `hiding_the_vat_block_restores_the_aggregate_tax_line`, `the_rounding_line_prints_even_when_payment_details_are_hidden`, `a_positive_adjustment_prints_without_a_forced_minus`, `an_unrounded_sale_prints_no_rounding_line`, `tolerance_and_rounding_print_as_two_distinctly_labelled_lines`, `a_localized_tolerance_label_is_used_when_supplied`.

Two mechanical adaptations are required and are the ONLY permitted edits:
1. The file header becomes:

```rust
use serde::{Deserialize, Serialize};

use super::escpos::{Alignment, CutMode, EscPosBuilder, FontSize, QrErrorCorrection};
use super::receipt_template::{CompanyInfo, PrintSettings};
```

2. Its two calls into the encoding API, which Task 8 changed, become:

```rust
    if let Some(s) = settings {
        b.set_encoding(s.text_encoding());
        let page = s.text_encoding().code_page();
        if page != 0 {
            b.set_code_page(page);
        }
    }
```

This keeps the reference's **observable** pre-Lane-E behaviour — `ESC t` suppressed for page 0 — which is exactly what the golden bytes must capture.

Register it in `apps/pos/src-tauri/src/printing/mod.rs`, replacing lines 1-7:

```rust
pub mod doc_encoder;
pub mod escpos;
#[cfg(test)]
pub mod golden_reference;
pub mod network;
pub mod receipt_template;
pub mod usb;
pub mod voucher_ticket;
#[cfg(target_os = "windows")]
pub mod windows;
```

- [ ] **Step 3: Strip `receipt_template.rs` down to what still has consumers**

Delete from `apps/pos/src-tauri/src/printing/receipt_template.rs` every item listed in **Files** above. What remains, in order: `CompanyInfo` (`voucher_ticket.rs:23` imports it), `PrintSettings` + its `text_encoding` / `cut_mode_enum` / `is_cut_enabled` / `Default`, `DrawerKickSettings`, `format_test_page`, `format_test_page_with_columns`, `format_drawer_kick` / `format_drawer_kick_with_settings`, and `mod tests_encoding_contract`.

- [ ] **Step 4: Replace the command**

`apps/pos/src-tauri/src/commands/printing.rs` — replace the import block at lines 1-5:

```rust
use crate::printing::{
    self, PrintError, PrinterConnectionType, PrinterInfo,
    doc_encoder::{self, ReceiptDoc},
    receipt_template::{self, DrawerKickSettings, PrintSettings},
    voucher_ticket::{self, VoucherTicketData},
};
```

and replace `print_receipt` (lines 36-65) with:

```rust
/// Print a receipt from a canonical ReceiptDoc.
///
/// The layout was decided in TypeScript (`buildReceiptDoc`); this command only
/// encodes and transports. It REPLACES the retired `print_receipt`, which took
/// a raw `ReceiptData` and let Rust lay the ticket out — a second template
/// beside the web preview and the server PDF (convention 11).
#[tauri::command]
pub async fn print_receipt_doc(
    doc: ReceiptDoc,
    connection_type: PrinterConnectionType,
    address: String,
    print_settings: Option<PrintSettings>,
) -> Result<(), PrintError> {
    let settings = print_settings.unwrap_or_default();
    let data = doc_encoder::encode(&doc, &settings);
    let copies = settings.copies.max(1);

    log::info!(
        "Printing receipt doc v{} ({} segments, {} cols, {} bytes, {} copies) to {:?}:{}",
        doc.version,
        doc.segments.len(),
        doc.columns,
        data.len(),
        copies,
        connection_type,
        address,
    );

    for _ in 0..copies {
        printing::send_to_printer(&connection_type, &address, &data).await?;
    }

    Ok(())
}
```

`apps/pos/src-tauri/src/lib.rs:31` — replace `commands::printing::print_receipt,` with:

```rust
            commands::printing::print_receipt_doc,
```

- [ ] **Step 5: Confirm nothing else references the retired symbols**

```bash
grep -rn "print_receipt\b" apps/pos/src-tauri/src apps/pos/src | grep -v print_receipt_doc | grep -v printReceiptAsPdf
grep -rn "format_receipt_with_settings\|format_receipt(" apps/pos/src-tauri/src
```
Expected: the first prints only the i18n label keys in `apps/pos/src/locales/*/pos.json` (button copy, unrelated); the second prints only hits inside `golden_reference.rs` and the doc comment at `voucher_ticket.rs:88`. Update that doc comment to point at `golden_reference.rs`.

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src-tauri/src
git commit -m "refactor(pos-tauri): doc_encoder + print_receipt_doc, retire print_receipt and format_receipt_with_settings

The old template is frozen under cfg(test) in printing/golden_reference.rs so the
golden-bytes regression can run in ONE cargo invocation. Rust written, not
compiled — proven by the single cargo run in Task 10."
```

---

### Task 10: cross-language contract + golden-bytes regression — **the single cargo run**

Two guarantees, proved in one `cargo test` invocation:

1. **Cross-language contract.** Vitest serialises the fixture's documents to a committed JSON file; a Rust test deserialises the *same* file and encodes it. If the TS segment shape and the Rust `serde` shape ever drift, this fails at deserialisation.
2. **Golden-bytes regression.** The frozen pre-Lane-E template (Task 9) encodes the same fixture; the new encoder's bytes are compared against it, and every difference must fall inside a declared whitelist. Anything else fails.

Whitelisted difference regions — the spec §5 lists four; this plan declares a **fifth**, because the new QR caption is a deliberate change the spec's §4.1 introduces but its §5 whitelist omits:

| # | Region | Why |
|---|---|---|
| 1 | The dropped `N° TVA : …` line | DEV-QA-085, owner-ruled dedup |
| 2 | The dropped fiscal-hash QR block | DEV-QA-086, owner ruling 6 |
| 3 | The `ESC t` prologue | DEV-QA-087, `ESC t` is now always emitted |
| 4 | Currency cells (`TND11.000` → `11.000 TND`, and the padding shift that follows) | DEV-QA-088 |
| 5 | **The added QR caption line** (`Scanner pour retour / échange`, Font B, above the token QR) | DEV-QA-086: spec §4.1 requires the label but §5's whitelist does not list it. Declared here so it is visible, not absorbed. |

**Files:**
- Create: `packages/shared/src/receipt/__tests__/contract.test.ts`
- Create (generated, committed): `packages/shared/src/receipt/fixtures/sampleReceipt.doc.json`
- Create (generated, committed): `apps/pos/src-tauri/tests/golden/sampleReceipt.legacy.json`
- Create (generated, committed): `apps/pos/src-tauri/tests/golden/{sale,refund,z}-{42,32}.bin` (6 files)
- Modify: `packages/shared/src/receipt/fixtures/sampleReceipt.ts` (add the refund and Z variants)
- Modify: `apps/pos/src-tauri/src/printing/doc_encoder.rs` (append `mod tests`)

**Interfaces:**
- Consumes: `buildReceiptDoc`, `sampleReceipt` (Tasks 3-6); `doc_encoder::encode`, `golden_reference::format_receipt_with_settings` (Task 9).
- Produces:
  - `sampleRefundReceipt: ReceiptData`, `sampleZReceipt: ReceiptData` in `fixtures/sampleReceipt.ts`
  - JSON contract keys: `"sale-42" | "sale-32" | "refund-42" | "refund-32" | "z-42" | "z-32"`
  - Legacy JSON keys: `"sale" | "refund" | "z"`

- [ ] **Step 1: Add the refund and Z fixtures**

Append to `packages/shared/src/receipt/fixtures/sampleReceipt.ts`:

```ts
/** The same ticket refunded: exercises the REMBOURSEMENT branch and both QRs. */
export const sampleRefundReceipt: ReceiptData = {
  ...sampleReceipt,
  receipt_number: 'RF-T1-2026-00000007',
  receipt_kind: 'refund',
  original_receipt_number: 'R-T1-2026-00000123',
  original_receipt_qr_token: 'v1:kid1:0199a0f3-5b1c-7c42-9f0e-2d7a1b3c4d5e:9f3a7c2e',
  qr_token: 'v1:kid1:0199b114-77aa-7ded-8c31-5e9f0a1b2c3d:1a2b3c4d',
  fiscal_hash: 'b7c2d4e69a1f30587ac4e2b1d9f60315e8a7b6c5d4e3f2a1b0c9d8e7f6a51234',
  labels: {
    ...sampleReceipt.labels,
    refund_header: 'REMBOURSEMENT',
    original_ticket: 'Ticket original :',
    original_qr_label: 'Scanner le ticket original :',
  },
};

/** A Z-report: no lines, no money code, a cash-count block. */
export const sampleZReceipt: ReceiptData = {
  ...sampleReceipt,
  receipt_number: 'Z-T1-2026-000042',
  lines: [],
  subtotal: '0.00',
  discount_amount: '0.00',
  tax_amount: '0.00',
  total: '0.00',
  currency_code: '',
  vat_breakdown: [],
  payments: [],
  change_due: '0.00',
  ht_totals: null,
  fiscal_hash: null,
  fiscal_signature: null,
  qr_token: null,
  show_vat_breakdown: false,
  show_fiscal_info: false,
  show_payment_details: false,
  show_customer: false,
  cash_counts: [
    { code: 'CASH', name: 'Espèces', expected: '150.000', actual: '155.000', variance: '5.000', direction: 'over' },
    { code: 'CARD', name: 'Carte', expected: '90.000', actual: '90.000', variance: '0.000', direction: 'balanced' },
  ],
  aggregate_variance: '5.000',
  manager_name: 'Jean',
  variance_reason: 'erreur de comptage',
  labels: {
    ...sampleReceipt.labels,
    cash_count_section_title: 'COMPTAGE CAISSE',
    cash_count_total_variance: 'Écart total :',
    cash_count_approved_by: 'Validé par :',
    cash_count_reason: 'Motif :',
    cash_count_col_tender: 'Moyen',
    cash_count_col_expected: 'Attendu',
    cash_count_col_actual: 'Réel',
    cash_count_col_variance: 'Écart',
  },
};
```

- [ ] **Step 2: Write the contract generator/assertor (Vitest)**

`packages/shared/src/receipt/__tests__/contract.test.ts`:

```ts
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { buildReceiptDoc } from '../buildReceiptDoc';
import { sampleReceipt, sampleRefundReceipt, sampleZReceipt } from '../fixtures/sampleReceipt';
import type { ReceiptColumns, ReceiptData, ReceiptDisplaySettings, ReceiptDoc } from '../index';

const here = dirname(fileURLToPath(import.meta.url));
const DOC_JSON = resolve(here, '../fixtures/sampleReceipt.doc.json');
const LEGACY_JSON = resolve(here, '../../../../../apps/pos/src-tauri/tests/golden/sampleReceipt.legacy.json');

const display: ReceiptDisplaySettings = {
  logo: false,
  subtotalMode: 'TTC',
  showVatBreakdown: true,
  showFiscalInfo: true,
  showPaymentDetails: true,
  showCustomer: true,
};

const KINDS: ReadonlyArray<readonly [string, ReceiptData]> = [
  ['sale', sampleReceipt],
  ['refund', sampleRefundReceipt],
  ['z', sampleZReceipt],
];
const WIDTHS: readonly ReceiptColumns[] = [42, 32];

function buildDocs(): Record<string, ReceiptDoc> {
  const docs: Record<string, ReceiptDoc> = {};
  for (const [kind, data] of KINDS) {
    for (const columns of WIDTHS) {
      docs[`${kind}-${String(columns)}`] = buildReceiptDoc(data, display, {
        columns,
        cutMode: 'partial',
        footerText: '',
      });
    }
  }
  return docs;
}

/**
 * The fixture in the PRE-Lane-E ReceiptData shape (`currency_symbol`, no
 * `locale`, no `ht_totals`), which the frozen Rust template consumes. Emitted
 * from the same source of truth so the two sides cannot describe different
 * tickets.
 */
function buildLegacy(): Record<string, unknown> {
  const legacy: Record<string, unknown> = {};
  for (const [kind, data] of KINDS) {
    const { currency_code: currencyCode, locale: _locale, ht_totals: _ht, ...rest } = data;
    legacy[kind] = { ...rest, currency_symbol: currencyCode };
  }
  return legacy;
}

function readOrWrite(path: string, content: string): string {
  if (!existsSync(path)) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, content, 'utf8');
    return content;
  }
  return readFileSync(path, 'utf8');
}

describe('cross-language contract artefacts', () => {
  it('sampleReceipt.doc.json matches the documents the builder produces today', () => {
    const content = `${JSON.stringify(buildDocs(), null, 2)}\n`;
    expect(readOrWrite(DOC_JSON, content)).toBe(content);
  });

  it('sampleReceipt.legacy.json matches the pre-Lane-E shape of the same fixture', () => {
    const content = `${JSON.stringify(buildLegacy(), null, 2)}\n`;
    expect(readOrWrite(LEGACY_JSON, content)).toBe(content);
  });

  it('every document declares version 1 and its own column count', () => {
    for (const [key, doc] of Object.entries(buildDocs())) {
      expect(doc.version).toBe(1);
      expect(String(doc.columns)).toBe(key.split('-')[1]);
      expect(doc.segments.length).toBeGreaterThan(5);
    }
  });
});
```

Run: `pnpm --filter @autoerp/shared test` — first run WRITES both JSON files, then passes. Re-run once to confirm it now ASSERTS them (green with the files in place). Inspect both files before committing.

- [ ] **Step 3: Write the Rust contract + golden test module**

Append to `apps/pos/src-tauri/src/printing/doc_encoder.rs`:

```rust
#[cfg(test)]
mod tests {
    use super::*;
    use crate::printing::golden_reference;
    use crate::printing::receipt_template::PrintSettings;
    use std::collections::BTreeMap;
    use std::fs;
    use std::path::PathBuf;

    const DOC_JSON: &str = include_str!(
        "../../../../../packages/shared/src/receipt/fixtures/sampleReceipt.doc.json"
    );
    const LEGACY_JSON: &str = include_str!("../../tests/golden/sampleReceipt.legacy.json");

    const KINDS: [&str; 3] = ["sale", "refund", "z"];
    const WIDTHS: [u8; 2] = [42, 32];

    fn settings(columns: u8) -> PrintSettings {
        PrintSettings {
            columns,
            cut_mode: "partial".to_string(),
            encoding: "cp1252".to_string(),
            footer_text: String::new(),
            copies: 1,
        }
    }

    fn golden_path(kind: &str, columns: u8) -> PathBuf {
        PathBuf::from(env!("CARGO_MANIFEST_DIR"))
            .join("tests/golden")
            .join(format!("{kind}-{columns}.bin"))
    }

    /// Read the committed golden, or write it on the first run so the artefact
    /// can be committed. Once committed it is an assertion, not a rewrite.
    fn golden_bytes(kind: &str, columns: u8, produced: &[u8]) -> Vec<u8> {
        let path = golden_path(kind, columns);
        if !path.exists() {
            fs::create_dir_all(path.parent().expect("golden dir")).expect("create golden dir");
            fs::write(&path, produced).expect("write golden");
            return produced.to_vec();
        }
        fs::read(&path).expect("read golden")
    }

    // ── Byte normalisation ────────────────────────────────────────────────
    //
    // The golden comparison is on the PRINTED TEXT layout, which is what the
    // four defects touch. Control sequences are stripped and asserted
    // separately (see `emphasis_commands_are_unchanged`), because the segment
    // model necessarily emits alignment/size toggles in a different ORDER
    // from the old stateful template while producing the same page.

    /// Strip every `GS ( k` block, returning the remaining bytes and the QR
    /// payloads that were stored (function 180, `cn=0x31 fn=0x50 m=0x30`).
    fn strip_qr_blocks(bytes: &[u8]) -> (Vec<u8>, Vec<String>) {
        let mut out = Vec::with_capacity(bytes.len());
        let mut payloads = Vec::new();
        let mut i = 0usize;

        while i < bytes.len() {
            if i + 5 <= bytes.len() && bytes[i] == 0x1D && bytes[i + 1] == 0x28 && bytes[i + 2] == 0x6B
            {
                let len = bytes[i + 3] as usize + ((bytes[i + 4] as usize) << 8);
                let body_start = i + 5;
                let body_end = (body_start + len).min(bytes.len());
                let body = &bytes[body_start..body_end];
                if body.len() >= 3 && body[0] == 0x31 && body[1] == 0x50 && body[2] == 0x30 {
                    payloads.push(String::from_utf8_lossy(&body[3..]).into_owned());
                }
                i = body_end;
                continue;
            }
            out.push(bytes[i]);
            i += 1;
        }

        (out, payloads)
    }

    /// Strip every ESC/GS command EscPosBuilder can emit, leaving text + LF.
    fn strip_control_sequences(bytes: &[u8]) -> Vec<u8> {
        let mut out = Vec::with_capacity(bytes.len());
        let mut i = 0usize;

        while i < bytes.len() {
            let skip = match (bytes.get(i), bytes.get(i + 1)) {
                (Some(0x1B), Some(0x40)) => 2,                                   // ESC @
                (Some(0x1B), Some(0x70)) => 5,                                   // ESC p m t1 t2
                (Some(0x1B), Some(b)) if matches!(b, 0x61 | 0x45 | 0x2D | 0x4D | 0x64 | 0x74) => 3,
                (Some(0x1D), Some(b)) if matches!(b, 0x21 | 0x56 | 0x48 | 0x68 | 0x77) => 3,
                (Some(0x1D), Some(0x6B)) => {
                    // GS k m n d1..dn — length-prefixed barcode.
                    let n = *bytes.get(i + 3).unwrap_or(&0) as usize;
                    4 + n
                }
                _ => 0,
            };
            if skip > 0 {
                i = (i + skip).min(bytes.len());
                continue;
            }
            out.push(bytes[i]);
            i += 1;
        }

        out
    }

    /// Whitelist regions 1, 4 and 5 (see the plan's table): drop the dedup'd
    /// VAT-number line and the new QR caption, remove the currency code and
    /// collapse runs of spaces so the padding shift the code move causes does
    /// not read as a layout change.
    fn canonical_lines(bytes: &[u8], currency_code: &str) -> Vec<String> {
        let (without_qr, _payloads) = strip_qr_blocks(bytes);
        let text = strip_control_sequences(&without_qr);
        let decoded: String = text.iter().map(|&byte| byte as char).collect(); // CP1252 low range

        decoded
            .split('\n')
            .filter(|line| !line.starts_with("N° TVA"))
            .filter(|line| !line.contains("Scanner pour retour"))
            .map(|line| {
                let stripped = if currency_code.is_empty() {
                    line.to_string()
                } else {
                    line.replace(currency_code, "")
                };
                stripped.split_whitespace().collect::<Vec<_>>().join(" ")
            })
            .filter(|line| !line.is_empty())
            .collect()
    }

    fn count_command(bytes: &[u8], pattern: &[u8]) -> usize {
        bytes.windows(pattern.len()).filter(|w| *w == pattern).count()
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    #[test]
    fn the_shared_package_and_rust_agree_on_the_document_shape() {
        let docs: BTreeMap<String, ReceiptDoc> =
            serde_json::from_str(DOC_JSON).expect("sampleReceipt.doc.json must deserialise");
        assert_eq!(docs.len(), 6);
        for kind in KINDS {
            for columns in WIDTHS {
                let doc = docs
                    .get(&format!("{kind}-{columns}"))
                    .unwrap_or_else(|| panic!("missing document {kind}-{columns}"));
                assert_eq!(doc.version, 1);
                assert_eq!(doc.columns, columns);
                assert!(!doc.segments.is_empty());
                assert!(
                    !doc.segments
                        .iter()
                        .any(|segment| matches!(segment, ReceiptSegment::Unknown)),
                    "{kind}-{columns} carries a segment kind this build does not know"
                );
            }
        }
    }

    #[test]
    fn the_encoded_sale_carries_the_fixed_accents_code_page_and_currency_cells() {
        let docs: BTreeMap<String, ReceiptDoc> = serde_json::from_str(DOC_JSON).expect("json");
        let bytes = encode(docs.get("sale-42").expect("sale-42"), &settings(42));

        // ESC @ then ESC t 16 (CP1252) — DEV-QA-087.
        assert_eq!(&bytes[0..2], &[0x1B, 0x40]);
        assert_eq!(&bytes[2..5], &[0x1B, 0x74, 0x10]);

        // "Café crème" in CP1252 — DEV-QA-087.
        let probe = [0x43u8, 0x61, 0x66, 0xE9, 0x20, 0x63, 0x72, 0xE8, 0x6D, 0x65];
        assert!(bytes.windows(probe.len()).any(|w| w == probe));

        // "Garçon" in CP1252.
        let garcon = [0x47u8, 0x61, 0x72, 0xE7, 0x6F, 0x6E];
        assert!(bytes.windows(garcon.len()).any(|w| w == garcon));

        // "11.000 TND" right-aligned; "TND11.000" gone — DEV-QA-088.
        let decoded: String = bytes.iter().map(|&b| b as char).collect();
        assert!(decoded.contains("11.000 TND"));
        assert!(!decoded.contains("TND11.000"));

        // The matricule prints once — DEV-QA-085.
        assert_eq!(decoded.matches("1234567/A/M/000").count(), 1);
    }

    #[test]
    fn a_sale_prints_exactly_one_qr_and_it_is_the_return_token() {
        let docs: BTreeMap<String, ReceiptDoc> = serde_json::from_str(DOC_JSON).expect("json");
        let bytes = encode(docs.get("sale-42").expect("sale-42"), &settings(42));
        let (_stripped, payloads) = strip_qr_blocks(&bytes);

        assert_eq!(payloads.len(), 1, "DEV-QA-086: one customer-facing QR");
        assert_eq!(
            payloads[0],
            "v1:kid1:0199a0f3-5b1c-7c42-9f0e-2d7a1b3c4d5e:9f3a7c2e"
        );
        // The fiscal hash is never a QR payload any more.
        assert!(!payloads[0].chars().all(|c| c.is_ascii_hexdigit()));
    }

    #[test]
    fn the_golden_bytes_differ_only_inside_the_whitelisted_regions() {
        let docs: BTreeMap<String, ReceiptDoc> = serde_json::from_str(DOC_JSON).expect("json");
        let legacy: BTreeMap<String, golden_reference::ReceiptData> =
            serde_json::from_str(LEGACY_JSON).expect("sampleReceipt.legacy.json must deserialise");

        for kind in KINDS {
            let data = legacy.get(kind).unwrap_or_else(|| panic!("legacy {kind}"));
            let currency = data.currency_symbol.clone();

            for columns in WIDTHS {
                let old = golden_reference::format_receipt_with_settings(
                    data,
                    Some(&settings(columns)),
                );
                let committed = golden_bytes(kind, columns, &old);
                assert_eq!(
                    old, committed,
                    "{kind}-{columns}: the frozen reference no longer reproduces its committed golden — \
                     golden_reference.rs was edited"
                );

                let new =
                    encode(docs.get(&format!("{kind}-{columns}")).expect("doc"), &settings(columns));

                assert_eq!(
                    canonical_lines(&committed, &currency),
                    canonical_lines(&new, &currency),
                    "{kind}-{columns}: a byte difference outside the five whitelisted regions"
                );
            }
        }
    }

    #[test]
    fn the_only_qr_removed_is_the_fiscal_hash_one() {
        let docs: BTreeMap<String, ReceiptDoc> = serde_json::from_str(DOC_JSON).expect("json");
        let legacy: BTreeMap<String, golden_reference::ReceiptData> =
            serde_json::from_str(LEGACY_JSON).expect("json");

        // sale: 2 QRs before (fiscal hash + token) -> 1 after.
        let old_sale = golden_reference::format_receipt_with_settings(
            legacy.get("sale").expect("sale"),
            Some(&settings(42)),
        );
        assert_eq!(strip_qr_blocks(&old_sale).1.len(), 2);
        assert_eq!(
            strip_qr_blocks(&encode(docs.get("sale-42").expect("doc"), &settings(42)))
                .1
                .len(),
            1
        );

        // refund: 3 QRs before (original token + fiscal hash + own token) -> 2 after.
        let old_refund = golden_reference::format_receipt_with_settings(
            legacy.get("refund").expect("refund"),
            Some(&settings(42)),
        );
        assert_eq!(strip_qr_blocks(&old_refund).1.len(), 3);
        assert_eq!(
            strip_qr_blocks(&encode(docs.get("refund-42").expect("doc"), &settings(42)))
                .1
                .len(),
            2
        );

        // Z: none before, none after.
        let old_z = golden_reference::format_receipt_with_settings(
            legacy.get("z").expect("z"),
            Some(&settings(42)),
        );
        assert_eq!(strip_qr_blocks(&old_z).1.len(), 0);
        assert_eq!(
            strip_qr_blocks(&encode(docs.get("z-42").expect("doc"), &settings(42)))
                .1
                .len(),
            0
        );
    }

    #[test]
    fn emphasis_commands_are_unchanged() {
        let docs: BTreeMap<String, ReceiptDoc> = serde_json::from_str(DOC_JSON).expect("json");
        let legacy: BTreeMap<String, golden_reference::ReceiptData> =
            serde_json::from_str(LEGACY_JSON).expect("json");

        let old = golden_reference::format_receipt_with_settings(
            legacy.get("sale").expect("sale"),
            Some(&settings(42)),
        );
        let new = encode(docs.get("sale-42").expect("doc"), &settings(42));

        // Same number of bold-on toggles and the same number of double-height
        // and double-width+height selections: the page's emphasis is identical
        // even though the segment model emits the toggles in its own order.
        assert_eq!(count_command(&old, &[0x1B, 0x45, 0x01]), count_command(&new, &[0x1B, 0x45, 0x01]));
        assert_eq!(count_command(&old, &[0x1D, 0x21, 0x01]), count_command(&new, &[0x1D, 0x21, 0x01]));
        assert_eq!(count_command(&old, &[0x1D, 0x21, 0x11]), count_command(&new, &[0x1D, 0x21, 0x11]));
        // One partial cut, one 4-line feed, on both sides.
        assert_eq!(count_command(&new, &[0x1D, 0x56, 0x01]), 1);
        assert_eq!(count_command(&new, &[0x1B, 0x64, 0x04]), 1);
    }

    #[test]
    fn an_unknown_segment_kind_is_skipped_instead_of_bricking_the_till() {
        let doc: ReceiptDoc = serde_json::from_str(
            r#"{"version":1,"columns":42,"segments":[{"kind":"hologram","spin":3},{"kind":"text","text":"OK"}]}"#,
        )
        .expect("unknown kinds must deserialise");
        let bytes = encode(&doc, &settings(42));
        let decoded: String = bytes.iter().map(|&b| b as char).collect();
        assert!(decoded.contains("OK"));
    }

    #[test]
    fn the_logo_segment_emits_no_bytes_until_ticket_e1() {
        let with_logo: ReceiptDoc = serde_json::from_str(
            r#"{"version":1,"columns":42,"segments":[{"kind":"logo"},{"kind":"text","text":"X"}]}"#,
        )
        .expect("json");
        let without: ReceiptDoc = serde_json::from_str(
            r#"{"version":1,"columns":42,"segments":[{"kind":"text","text":"X"}]}"#,
        )
        .expect("json");
        assert_eq!(encode(&with_logo, &settings(42)), encode(&without, &settings(42)));
    }
}
```

`golden_reference::ReceiptData` must therefore be `pub` inside that `#[cfg(test)]` module, and `serde_json` must be available to tests. Add to `apps/pos/src-tauri/Cargo.toml` under `[dev-dependencies]` — `serde_json` is already a normal dependency (line 26), so no new crate is pulled; no change is required. Confirm with `grep -n 'serde_json' apps/pos/src-tauri/Cargo.toml` before running.

- [ ] **Step 4: THE cargo run**

**ONE run, after `sysctl vm.swapusage` < 9000M, orchestrator-approved.**

```bash
sysctl vm.swapusage          # must read under 9000M before proceeding
cd apps/pos/src-tauri && cargo test --lib
```

Expected: PASS. On the first execution the six `tests/golden/*.bin` files do not exist and are written, then compared against themselves — so the run is green and the artefacts are produced. Every other assertion is a real comparison.

If the run cannot be made (swap too high, approval withheld), **stop**: do not mark the task complete, and record in the PR description that the Rust contract and golden-bytes tests are **unverified locally** (spec §7 risk 3). Do not claim any verification level the run did not reach.

If the run fails, debug and re-run only with a fresh swap check and a fresh approval.

- [ ] **Step 5: Inspect and commit the artefacts**

```bash
xxd apps/pos/src-tauri/tests/golden/sale-42.bin | head -40
```
Confirm `1B 40` then `1B 74 10` at the head of the file, and that `4E B0 20 54 56 41` (`N° TVA`) appears in the OLD golden (it is the line the new encoder drops).

```bash
git add packages/shared/src/receipt apps/pos/src-tauri/tests/golden apps/pos/src-tauri/src/printing/doc_encoder.rs
git commit -m "test(pos-tauri): cross-language ReceiptDoc contract + golden-bytes whitelist regression

Verified by ONE cargo test --lib run (swap checked, orchestrator-approved).
Whitelisted diff regions: dropped N° TVA line (DEV-QA-085), dropped fiscal-hash
QR (DEV-QA-086), ESC t prologue (DEV-QA-087), currency cells (DEV-QA-088), and
the added QR caption — the fifth region, declared by the plan because spec §5's
whitelist omits it."
```

---

### Task 11: Reprint fiscal parity — a display setting must never move a signed byte

Spec §7 risk 1 and B12: the tax-id dedup and the HT/TTC switch are presentation. The signed payload, its hash and the DUPLICATA marker must be byte-identical whatever the display settings are. The three existing parity suites prove the *encoder* is unchanged; this task adds the explicit cross-check the spec §5 row asks for — the same sale printed twice under different display settings.

**Files:**
- Create: `apps/pos/src/lib/fiscal/__tests__/receiptDisplaySettingsDoNotMoveSignedBytes.test.ts`
- Test (must stay green and **unmodified**): `apps/pos/src/lib/fiscal/__tests__/saleReceiptV5CanonicalParity.test.ts`, `apps/pos/src/lib/fiscal/__tests__/saleReceiptV2CanonicalParity.test.ts`, `apps/pos/src/lib/fiscal/payloads/__tests__/SaleReceiptV1V2ByteStability.test.ts` + its snapshot

**Interfaces:**
- Consumes: `buildReceiptDoc` (Tasks 4-6), `sampleReceipt` (Task 3), `buildSaleReceiptV5Payload` + `FiscalEventCanonicalEncoder` (existing, see `saleReceiptV5CanonicalParity.test.ts:3-5`).
- Produces: no new exports.

- [ ] **Step 1: Write the test**

`apps/pos/src/lib/fiscal/__tests__/receiptDisplaySettingsDoNotMoveSignedBytes.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { buildReceiptDoc } from '@autoerp/shared/src/receipt';
import { sampleReceipt } from '@autoerp/shared/src/receipt/fixtures/sampleReceipt';
import type { ReceiptDisplaySettings, ReceiptPrintContext } from '@autoerp/shared/src/receipt';

/**
 * B12 / spec §7 risk 1. `logo` and `subtotalMode` are DISPLAY options: the
 * reprint of an issued ticket may look different, but nothing fiscal may move.
 *
 * The signed payload is built from the cart and the sealed receipt, never from
 * the printed document — `buildReceiptDoc` has no path to it. This test pins
 * that separation so a future refactor cannot quietly create one.
 */

const print: ReceiptPrintContext = { columns: 42, cutMode: 'partial', footerText: '' };

const ttc: ReceiptDisplaySettings = {
  logo: false,
  subtotalMode: 'TTC',
  showVatBreakdown: true,
  showFiscalInfo: true,
  showPaymentDetails: true,
  showCustomer: true,
};
const htWithLogo: ReceiptDisplaySettings = { ...ttc, subtotalMode: 'HT', logo: true };

function fiscalSurface(display: ReceiptDisplaySettings, isReprint: boolean) {
  const doc = buildReceiptDoc({ ...sampleReceipt, is_reprint: isReprint }, display, print);
  const texts = doc.segments.flatMap((segment) => (segment.kind === 'text' ? [segment.text] : []));
  const qr = doc.segments.flatMap((segment) => (segment.kind === 'qr' ? [segment.payload] : []));
  const totalRow = doc.segments.find(
    (segment) => segment.kind === 'two-column' && segment.right === '10.000 TND' && segment.bold === true,
  );
  return {
    hash: texts.find((line) => line.startsWith('Hash:')),
    signature: texts.find((line) => line.startsWith('Sig:')),
    duplicata: texts.includes('DUPLICATA'),
    qr,
    total: totalRow?.kind === 'two-column' ? totalRow.right : undefined,
  };
}

describe('display settings never move a fiscal byte', () => {
  it('prints the same hash, signature and QR token under TTC and under HT+logo', () => {
    const first = fiscalSurface(ttc, false);
    const second = fiscalSurface(htWithLogo, false);

    expect(second.hash).toBe(first.hash);
    expect(second.signature).toBe(first.signature);
    expect(second.qr).toEqual(first.qr);
  });

  it('prints the same TOTAL under both modes — HT restates the subtotal, never the total', () => {
    expect(fiscalSurface(htWithLogo, false).total).toBe('10.000 TND');
    expect(fiscalSurface(ttc, false).total).toBe('10.000 TND');
  });

  it('keeps the DUPLICATA marker on a reprint whatever the display settings are', () => {
    expect(fiscalSurface(ttc, true).duplicata).toBe(true);
    expect(fiscalSurface(htWithLogo, true).duplicata).toBe(true);
    expect(fiscalSurface(htWithLogo, false).duplicata).toBe(false);
  });

  it('the document builder is not reachable from the signed payload module graph', async () => {
    const payloadModule = await import('../payloads/SaleReceiptV5Payload');
    expect(Object.keys(payloadModule)).not.toContain('buildReceiptDoc');
  });
});
```

- [ ] **Step 2: Run it and prove it fails for the right reason first**

Temporarily change the `total` assertion to `'9.175 TND'` (the HT subtotal) and run:

Run: `pnpm --filter @autoerp/pos vitest run src/lib/fiscal/__tests__/receiptDisplaySettingsDoNotMoveSignedBytes.test.ts`
Expected: FAIL on that one assertion — proving the test observes the real TOTAL rather than passing vacuously. Restore the assertion.

- [ ] **Step 3: Run the whole fiscal suite**

Run: `pnpm --filter @autoerp/pos vitest run src/lib/fiscal`
Expected: PASS, including `saleReceiptV5CanonicalParity`, `saleReceiptV2CanonicalParity` and `SaleReceiptV1V2ByteStability`.

Run: `git status --short apps/pos/src/lib/fiscal`
Expected: only the new test file. If any existing parity file or snapshot is modified, **revert it** — that is the guard, not a thing to update.

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/lib/fiscal/__tests__/receiptDisplaySettingsDoNotMoveSignedBytes.test.ts
git commit -m "test(pos-fiscal): display settings never move a signed byte (B12 reprint parity)"
```

---

### Task 12: Glossary row, and the PR gate

Convention 11 requires the `Receipt` noun to name its canonical surface in the same lane that changes it. `docs/glossary.md:78` says today: *"A sealed POS sale (`SALE_RECEIPT` event, `unit_price` tax-inclusive); its refund is a `pos_receipt_refund`." · `pos_receipts` / `Fiscal` · POS device · ticket*. After this PR the printed artefact has one builder, one encoder and one declared divergent surface.

**Files:**
- Modify: `docs/glossary.md:78` (the **Receipt** row) and add a **Voucher ticket** row beside it
- Modify: `docs/qa/DEV-QA-registry.md` rows DEV-QA-085…088 (status column)

**Interfaces:** documentation only.

- [ ] **Step 1: Replace the Receipt row**

`docs/glossary.md:78`:

```markdown
| **Receipt** | A sealed POS sale (`SALE_RECEIPT` event, `unit_price` tax-inclusive); its refund is a `pos_receipt_refund`. Its **printed surface** is the `ReceiptDoc` built by `@autoerp/shared/src/receipt` (`buildReceiptDoc`) — the single layout, consumed by the device **encoder** `apps/pos/src-tauri/src/printing/doc_encoder.rs` and by the back-office preview `apps/web/src/features/settings/components/ReceiptPreview.tsx`. The server PDF `apps/api/resources/views/pos/receipt.blade.php` is a **declared divergent surface** (tax-id lines, money placement, logo, header text, country-forced visibility) — unification is ticket E-3. | `pos_receipts` / `Fiscal`; layout in `packages/shared/src/receipt` | POS device (print); Settings → Company → Receipt (display settings + preview) | ticket, reçu |
```

- [ ] **Step 2: Add the Voucher ticket row**

Immediately after the Receipt row:

```markdown
| **Voucher ticket** | The redeemable instrument printed ALONGSIDE a refund receipt when the refund destination is `store_voucher` — a different artefact, not a receipt variant: no line items, a dominant voucher code, a QR encoding that code. It keeps its own Rust layout (`apps/pos/src-tauri/src/printing/voucher_ticket.rs`), including its own company header (`:117-138`) and the currency-placement bug at `:172`; porting it onto `ReceiptDoc` is ticket E-2. | `vouchers` / `POS`; layout in `printing/voucher_ticket.rs` | POS device (printed with the AVOIR) | bon d'achat |
```

- [ ] **Step 3: Update the registry**

In `docs/qa/DEV-QA-registry.md`, move rows **DEV-QA-085**, **086**, **087** and **088** from `EN COURS` to `CORRIGÉ (PR 1, en attente de recette)` and append to each row's evidence cell the verification level actually reached — for example, for DEV-QA-087: *"corrigé `escpos.rs` TextEncoding + `receipt_template.rs` text_encoding + page de test; vérifié par tests unitaires Rust (1 exécution cargo) ; **non vérifié sur imprimante physique**"*. **DEV-QA-089 stays `OUVERT`** — it is ticket E-4, out of this lane.

- [ ] **Step 4: Run the full PR gate**

```bash
pnpm --filter @autoerp/shared test
pnpm --filter @autoerp/shared typecheck
pnpm --filter @autoerp/pos test
pnpm --filter @autoerp/pos lint
pnpm --filter @autoerp/pos typecheck
pnpm --filter @autoerp/web typecheck
node apps/web/tools/audit-i18n-completeness.mjs
```

All must pass. `cargo test` is NOT re-run — Task 10 was the single approved invocation.

- [ ] **Step 5: Write the PR description**

It must state, in this order: the four defects fixed with their DEV-QA ids; the architecture change (one TS builder, Rust as encoder, `print_receipt` and `format_receipt_with_settings` removed, old template frozen under `cfg(test)`); the verification level reached for each layer — **unit (Vitest) for the builder, unit (one cargo run) for the encoder and the golden regression, and NOT verified on a physical printer**; the five whitelisted golden-diff regions including the fifth this plan declared; the deviations listed in the plan's self-review; and the open tickets E-1…E-6. No claim beyond what was run (CLAUDE.md rule 5, rule 23 step 2).

- [ ] **Step 6: Commit**

```bash
git add docs/glossary.md docs/qa/DEV-QA-registry.md
git commit -m "docs(glossary): Receipt row names the ReceiptDoc surfaces; Voucher ticket row added (DEV-QA-085..088)"
```

---

## Self-review

**1. Spec coverage.** Every PR-1 row of the spec's Delivery table maps to a task: `packages/shared/src/receipt` → Tasks 1-6; POS wiring → Task 7; Rust `doc_encoder` → Task 9; §4.1 tax-id dedup + QR → Tasks 4 and 6; §4.2 HT/TTC → Task 5; §4.3 encoding → Task 8; §4.4 currency → Tasks 2, 3, 5; glossary row → Task 12; §5 shared Vitest / cross-language contract / golden bytes / reprint parity / POS Vitest → Tasks 4-6, 10, 11, 7.

**Gaps and deviations, all deliberate:**
- **Spec §4.2's "Remise HT = Σ per-rate remise bases" has no source field.** `discount_allocated` is the rate group's share of the **TTC** remise, proved at `FiscalPayloadConstraintValidator.php:1595-1639`. Task 3 derives the HT share with one rounded division per rate on the device (`computeHtTotals`) and makes the identity exact by construction. This is money arithmetic the spec did not specify — **it needs the owner's confirmation before the PR merges.**
- **A fifth golden-diff whitelist region** (the new QR caption) is declared in Task 10; spec §5 lists four.
- **i18n key namespace.** The brief names `pos:receipt.qrScanLabel` / `.subtotalHt` / `.discountHt`. This plan uses `pos:receiptLabel.*` instead, because `buildReceiptLabels()` (`buildReceiptData.ts:557-606`) reads every ticket label from that one namespace and a second namespace for four of them would be a new surface for one concept (convention 11). A **fourth** key, `pos:receiptLabel.totalTtc`, is added because owner ruling 7 requires the HT layout to print `TOTAL TTC`.
- **`apps/pos` has no `ar` locale** (only `en` and `fr`), so the POS keys land in two files, not three. The Arabic web strings are PR 2.
- **`getCurrencySymbol` survives** for `buildVoucherTicketData`; the voucher ticket keeps its money bug until ticket E-2, as the spec declares in §4.4.
- **The golden comparison is on printed text, not raw bytes.** The segment model necessarily emits style toggles in a different order from the old stateful template. Control sequences are stripped from the comparison and asserted separately by `emphasis_commands_are_unchanged` (Task 10). Stated so no one reads "golden bytes" as more than it is.
- **`packages/shared` gains `vitest` as a devDependency** (Task 1), which needs one `pnpm add`. There is no way to run "shared package Vitest" — the spec's own gate — without a runner in that package.
- **Physical printer behaviour is not verified.** `ESC t 16` support on the terminal's firmware stays unknown until Dhouha prints the fixture (spec §7 risk 4). The PR says so.

**2. Placeholder scan.** No `TBD`, no "add error handling", no "similar to Task N". Every code step carries the literal code. The one place a task defers to the codebase — the offline builder's VAT property name in Task 3 Step 5 — names the exact lines to read (`buildReceiptData.ts:300-330`) and forbids inventing a name.

**3. Type consistency.** `buildReceiptDoc(data, display, print)` keeps that argument order in Tasks 4, 5, 6, 7, 11 and in PR 2. `ReceiptPrintContext` is `{ columns, cutMode, footerText }` everywhere. `formatReceiptMoney(amount, currency, locale)` and `padTwoColumns/padThreeColumns/padColumns` keep their Task-2 signatures in Tasks 5, 6 and PR 2. `resolveReceiptDisplaySettings` / `currentReceiptDisplaySettings` / `toPrintContext` are used only as declared in Task 7. The Rust `encode(doc, settings)` signature in Task 9 matches every call in Task 10. `ReceiptHtTotals { subtotal_ht, discount_ht }` is spelled identically in Task 3's type, its fixture, and Task 5's reader.
